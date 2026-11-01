<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Range;

use Hryvinskyi\BotVerification\Api\Data\AddressFamily;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;

/**
 * Immutable snapshot of a published address list, answering whether an address lies in it by binary search
 *
 * The constructor refuses a family whose length is not a whole number of records and a fetch moment that is not
 * positive. It does not re-check that the records are sorted and merged: that check is linear in the size of the
 * list and would run on every request that is looked up. Sorted, merged records are the snapshot builder's
 * guarantee, and storage keeps the bytes verbatim.
 *
 * The lookup allocates nothing per step: it compares the address with the records in place, where byte order equals
 * numeric order because every address of a family has the same width and is big-endian.
 */
class RangeSnapshot implements RangeSnapshotInterface
{
    /**
     * @param string $ipv4Ranges Packed IPv4 records of 4-byte start and 4-byte end, sorted by start, merged
     * @param string $ipv6Ranges Packed IPv6 records of 16-byte start and 16-byte end, sorted by start, merged
     * @param int $fetchedAt Moment the list was fetched, unix seconds
     * @throws \InvalidArgumentException When a family is not a whole number of records or the moment is not positive
     */
    public function __construct(
        private readonly string $ipv4Ranges,
        private readonly string $ipv6Ranges,
        private readonly int $fetchedAt
    ) {
        if (strlen($ipv4Ranges) % self::IPV4_RECORD_SIZE !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'IPv4 ranges must be whole records of %d bytes; %d bytes given.',
                self::IPV4_RECORD_SIZE,
                strlen($ipv4Ranges)
            ));
        }
        if (strlen($ipv6Ranges) % self::IPV6_RECORD_SIZE !== 0) {
            throw new \InvalidArgumentException(sprintf(
                'IPv6 ranges must be whole records of %d bytes; %d bytes given.',
                self::IPV6_RECORD_SIZE,
                strlen($ipv6Ranges)
            ));
        }
        if ($fetchedAt <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'The moment a list was fetched must be positive; %d given.',
                $fetchedAt
            ));
        }
    }

    /**
     * @inheritDoc
     */
    public function contains(string $address): bool
    {
        $ranges = $this->rangesOfFamily($address);
        if ($ranges === null) {
            return false;
        }
        $width = strlen($address);
        $recordSize = 2 * $width;

        $low = 0;
        $high = intdiv(strlen($ranges), $recordSize) - 1;
        $candidate = -1;
        while ($low <= $high) {
            $middle = ($low + $high) >> 1;
            if (substr_compare($ranges, $address, $middle * $recordSize, $width) <= 0) {
                $candidate = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $candidate >= 0
            && substr_compare($ranges, $address, $candidate * $recordSize + $width, $width) >= 0;
    }

    /**
     * @inheritDoc
     */
    public function holdsFamilyOf(string $address): bool
    {
        $ranges = $this->rangesOfFamily($address);

        return $ranges !== null && $ranges !== '';
    }

    /**
     * @inheritDoc
     */
    public function isOlderThan(int $seconds, int $now): bool
    {
        return $now - $this->fetchedAt > $seconds;
    }

    /**
     * @inheritDoc
     */
    public function getFetchedAt(): int
    {
        return $this->fetchedAt;
    }

    /**
     * @inheritDoc
     */
    public function getIpv4Ranges(): string
    {
        return $this->ipv4Ranges;
    }

    /**
     * @inheritDoc
     */
    public function getIpv6Ranges(): string
    {
        return $this->ipv6Ranges;
    }

    /**
     * @inheritDoc
     *
     * A span is the difference of two big-endian numbers, so it is accumulated byte by byte from the most significant
     * end as the sum of the bytes' differences, each step multiplying by 256. Every partial value is the difference of
     * two prefixes of the same width, never negative because the last address is not below the first, and never larger
     * than the final span: nothing cancels, and a span below 2^53 is exact.
     */
    public function coveredAddresses(AddressFamily $family): float
    {
        $ranges = match ($family) {
            AddressFamily::Ipv4 => $this->ipv4Ranges,
            AddressFamily::Ipv6 => $this->ipv6Ranges,
        };
        $width = intdiv($family->bits(), 8);
        $length = strlen($ranges);

        $covered = 0.0;
        for ($start = 0; $start < $length; $start += 2 * $width) {
            $end = $start + $width;
            $span = 0.0;
            for ($byte = 0; $byte < $width; $byte++) {
                $span = $span * 256 + (ord($ranges[$end + $byte]) - ord($ranges[$start + $byte]));
            }
            $covered += $span + 1;
        }

        return $covered;
    }

    /**
     * @inheritDoc
     */
    public function countRanges(): int
    {
        return intdiv(strlen($this->ipv4Ranges), self::IPV4_RECORD_SIZE)
            + intdiv(strlen($this->ipv6Ranges), self::IPV6_RECORD_SIZE);
    }

    /**
     * The records of the family an address belongs to
     *
     * A record is a range's first and last address, so an address belongs to the family whose records are twice its
     * length.
     *
     * @param string $address Packed address
     * @return string|null The family's records, '' when the list holds none; null for an address of no family
     */
    private function rangesOfFamily(string $address): ?string
    {
        return match (2 * strlen($address)) {
            self::IPV4_RECORD_SIZE => $this->ipv4Ranges,
            self::IPV6_RECORD_SIZE => $this->ipv6Ranges,
            default => null,
        };
    }
}
