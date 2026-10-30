<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Storage;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Magento\Framework\FlagManager;

/**
 * Durable storage of snapshots: one row of the application's flag table per list
 *
 * A snapshot is stored as its fetch moment and its two families of packed records, each family base64-encoded
 * because the flag table holds its value as JSON text, which cannot carry raw bytes.
 *
 * A stored value this module cannot read - not the shape it writes, a family that is not valid base64 or not a whole
 * number of records, a fetch moment that is not a positive integer, a value that is not JSON at all - is treated as
 * never stored: the list cannot vouch for or accuse anybody until the next successful refresh overwrites it. A read
 * that fails is treated the same way. Reading never throws, because it runs while a request that claims a crawler is
 * being judged, and an unreadable row must not turn that request into an error. Saving does throw when the write
 * fails, so a refresh that could not store a list says so.
 */
class FlagRangeSnapshotRepository implements RangeSnapshotRepositoryInterface
{
    private const FLAG_CODE_PREFIX = 'hryvinskyi_bot_verification_';
    private const KEY_IPV4 = 'ipv4';
    private const KEY_IPV6 = 'ipv6';
    private const KEY_FETCHED_AT = 'fetched_at';

    /**
     * @param FlagManager $flagManager Reads and writes the flag table by code
     * @param RangeSnapshotInterfaceFactory $snapshotFactory Creates the snapshot read back
     */
    public function __construct(
        private readonly FlagManager $flagManager,
        private readonly RangeSnapshotInterfaceFactory $snapshotFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function find(string $listCode): ?RangeSnapshotInterface
    {
        try {
            $stored = $this->flagManager->getFlagData($this->flagCode($listCode));
        } catch (\Exception) {
            // A value that is not JSON is retried as PHP-serialised data, which throws on anything else.
            return null;
        }
        if (!is_array($stored)) {
            return null;
        }

        $ipv4Ranges = $this->decodeFamily($stored[self::KEY_IPV4] ?? null, RangeSnapshotInterface::IPV4_RECORD_SIZE);
        $ipv6Ranges = $this->decodeFamily($stored[self::KEY_IPV6] ?? null, RangeSnapshotInterface::IPV6_RECORD_SIZE);
        $fetchedAt = $stored[self::KEY_FETCHED_AT] ?? null;
        if ($ipv4Ranges === null || $ipv6Ranges === null || !is_int($fetchedAt) || $fetchedAt <= 0) {
            return null;
        }

        return $this->snapshotFactory->create([
            'ipv4Ranges' => $ipv4Ranges,
            'ipv6Ranges' => $ipv6Ranges,
            'fetchedAt' => $fetchedAt,
        ]);
    }

    /**
     * @inheritDoc
     */
    public function save(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        $this->flagManager->saveFlag($this->flagCode($listCode), [
            self::KEY_IPV4 => base64_encode($snapshot->getIpv4Ranges()),
            self::KEY_IPV6 => base64_encode($snapshot->getIpv6Ranges()),
            self::KEY_FETCHED_AT => $snapshot->getFetchedAt(),
        ]);
    }

    /**
     * Code of the flag row that holds a list
     *
     * @param string $listCode Code of the published address list
     * @return string
     */
    private function flagCode(string $listCode): string
    {
        return self::FLAG_CODE_PREFIX . $listCode;
    }

    /**
     * Packed records of one family, decoded from their stored form
     *
     * @param mixed $encoded The stored value of the family
     * @param int $recordLength Length of one record of the family, in bytes
     * @return string|null Null when the value is not base64 text of a whole number of records
     */
    private function decodeFamily(mixed $encoded, int $recordLength): ?string
    {
        if (!is_string($encoded)) {
            return null;
        }
        $ranges = base64_decode($encoded, true);
        if ($ranges === false || strlen($ranges) % $recordLength !== 0) {
            return null;
        }

        return $ranges;
    }
}
