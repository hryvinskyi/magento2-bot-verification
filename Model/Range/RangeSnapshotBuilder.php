<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Range;

use Hryvinskyi\BotVerification\Api\AddressParserInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * Turns CIDR prefixes into the sorted, merged records a snapshot searches
 *
 * A prefix is taken only in its exact form: one address, one slash, a length of digits no longer than the address
 * has bits. Host bits set in the address are cleared, so '192.0.2.77/24' covers 192.0.2.0 to 192.0.2.255.
 *
 * An IPv6 prefix that lies wholly inside the space of IPv6 addresses carrying an IPv4 address (`::ffff:a.b.c.d/n`
 * with n of at least 96) is read as the IPv4 prefix `a.b.c.d/(n-96)`. Client addresses of that form are read as the
 * IPv4 address they carry, so the range has to be an IPv4 range to ever match. Every other prefix keeps its family.
 *
 * Within a family, records are sorted by start, and a range that starts at or before the end of the previous one,
 * or at the address right after it, is merged into it. The number of records is then the number of contiguous blocks
 * of address space, however the publisher splits the same space into prefixes.
 */
class RangeSnapshotBuilder implements RangeSnapshotBuilderInterface
{
    /**
     * @param RangeSnapshotInterfaceFactory $snapshotFactory Creates the snapshot of the merged records
     */
    public function __construct(
        private readonly RangeSnapshotInterfaceFactory $snapshotFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function build(array $prefixes, int $fetchedAt): RangeSnapshotInterface
    {
        if ($prefixes === []) {
            throw new RangeListRejectedException(__('The published address list holds no prefixes.'));
        }

        // Families are keyed by the size of their records: a range's first address followed by its last.
        $families = [RangeSnapshotInterface::IPV4_RECORD_SIZE => [], RangeSnapshotInterface::IPV6_RECORD_SIZE => []];
        foreach ($prefixes as $prefix) {
            $range = $this->toRange($prefix);
            if ($range === null) {
                throw new RangeListRejectedException(
                    __('The address range "%1" is not a valid CIDR prefix.', $prefix)
                );
            }
            $families[2 * strlen($range['start'])][] = $range;
        }

        return $this->snapshotFactory->create([
            'ipv4Ranges' => $this->pack($families[RangeSnapshotInterface::IPV4_RECORD_SIZE]),
            'ipv6Ranges' => $this->pack($families[RangeSnapshotInterface::IPV6_RECORD_SIZE]),
            'fetchedAt' => $fetchedAt,
        ]);
    }

    /**
     * First and last address of a CIDR prefix, packed
     *
     * The address is checked before it is packed: packing an invalid address raises a warning, which the
     * application's error handler turns into an exception.
     *
     * @param string $prefix CIDR prefix as published
     * @return array{start: string, end: string}|null Both 4 bytes (IPv4) or both 16 bytes (IPv6); null when the
     *                                                prefix is not a valid CIDR prefix
     */
    private function toRange(string $prefix): ?array
    {
        $parts = explode('/', $prefix);
        if (count($parts) !== 2) {
            return null;
        }
        [$address, $length] = $parts;
        if (filter_var($address, FILTER_VALIDATE_IP) !== $address || !ctype_digit($length)) {
            return null;
        }
        $packed = inet_pton($address);
        if ($packed === false || (int) $length > 8 * strlen($packed)) {
            return null;
        }

        $networkBits = (int) $length;
        $mappedPrefixBits = 8 * strlen(AddressParserInterface::IPV4_MAPPED_PREFIX);
        if (strlen($packed) === 16
            && $networkBits >= $mappedPrefixBits
            && str_starts_with($packed, AddressParserInterface::IPV4_MAPPED_PREFIX)
        ) {
            $packed = substr($packed, strlen(AddressParserInterface::IPV4_MAPPED_PREFIX));
            $networkBits -= $mappedPrefixBits;
        }
        $start = '';
        $end = '';
        foreach (str_split($packed) as $index => $byte) {
            $maskBits = max(0, min(8, $networkBits - 8 * $index));
            $mask = (0xFF << (8 - $maskBits)) & 0xFF;
            $start .= chr(ord($byte) & $mask);
            $end .= chr(ord($byte) | (~$mask & 0xFF));
        }

        return ['start' => $start, 'end' => $end];
    }

    /**
     * Records of one family, sorted by start, with overlapping and touching ranges merged
     *
     * @param list<array{start: string, end: string}> $ranges Ranges of one family, in any order
     * @return string Concatenated `start . end` records; '' for no range
     */
    private function pack(array $ranges): string
    {
        usort($ranges, static fn (array $left, array $right): int => strcmp($left['start'], $right['start']));

        $packed = '';
        $current = null;
        foreach ($ranges as $range) {
            if ($current !== null && $this->joins($range['start'], $current['end'])) {
                $current['end'] = strcmp($range['end'], $current['end']) > 0 ? $range['end'] : $current['end'];
                continue;
            }
            if ($current !== null) {
                $packed .= $current['start'] . $current['end'];
            }
            $current = $range;
        }
        if ($current !== null) {
            $packed .= $current['start'] . $current['end'];
        }

        return $packed;
    }

    /**
     * Whether a range starting at an address joins a range ending at another: it starts inside it or right after it
     *
     * @param string $start First address of the later range, packed
     * @param string $end Last address of the earlier range, packed as wide
     * @return bool
     */
    private function joins(string $start, string $end): bool
    {
        return strcmp($start, $end) <= 0 || $start === $this->successor($end);
    }

    /**
     * The address right after a packed address
     *
     * @param string $address Packed address, big-endian
     * @return string|null The next address, as wide; null for the last address of the space, which has none
     */
    private function successor(string $address): ?string
    {
        for ($index = strlen($address) - 1; $index >= 0; $index--) {
            $byte = ord($address[$index]);
            if ($byte < 0xFF) {
                return substr($address, 0, $index) . chr($byte + 1) . str_repeat("\0", strlen($address) - $index - 1);
            }
        }

        return null;
    }
}
