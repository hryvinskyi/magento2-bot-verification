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
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Magento\Framework\Cache\FrontendInterface;

/**
 * Serves snapshots from the application cache in front of the durable storage
 *
 * - Key: the list code only. The ranges a list holds are the same for every store, locale and visitor.
 * - Value: the fetch moment and the length of the IPv4 records in an 8-byte header, then the IPv4 records, then the
 *   IPv6 records, as stored by the snapshot. A list that was never stored is cached as a short marker, so a list
 *   nobody has fetched yet does not send every request that names it to the durable storage.
 * - Invalidation: a save writes the new value through to the cache, overwriting a cached value or marker, so a
 *   refresh never causes a miss. The lifetimes - a day for a value, five minutes for the marker - are a safety net,
 *   not the means of invalidation.
 * - A miss costs one read of the durable storage per process that misses; the value is cached right after. It
 *   happens after the cache is flushed, or when a list has had no successful save for a day.
 * - A cached value this class cannot read is a miss: the durable storage is read and the value cached again.
 * - Each list is also remembered by this instance once read, so a list asked for twice is read once. The instance
 *   lives for one request in a web process, and for the whole process in a cron or console run, where the saves of
 *   that run go through it and keep it current.
 */
class CachedRangeSnapshotRepository implements RangeSnapshotRepositoryInterface
{
    private const KEY_PREFIX = 'bot_verification_range_';

    /**
     * Value cached for a list that was never stored; shorter than any header, so no value is ever taken for it
     */
    private const NEVER_STORED = 'none';

    private const VALUE_LIFETIME = 86400;
    private const NEVER_STORED_LIFETIME = 300;

    /**
     * Length of the header: the fetch moment and the length of the IPv4 records, 4 bytes each, big-endian
     */
    private const HEADER_LENGTH = 8;

    /**
     * @var array<string, RangeSnapshotInterface|null> Lists read or saved by this instance, by list code; null when
     *                                                  the list was never stored
     */
    private array $remembered = [];

    /**
     * @param RangeSnapshotRepositoryInterface $inner Durable storage of the snapshots
     * @param FrontendInterface $cache Cache to serve the snapshots from
     * @param RangeSnapshotInterfaceFactory $snapshotFactory Creates the snapshot read from the cache
     */
    public function __construct(
        private readonly RangeSnapshotRepositoryInterface $inner,
        private readonly FrontendInterface $cache,
        private readonly RangeSnapshotInterfaceFactory $snapshotFactory
    ) {
    }

    /**
     * @inheritDoc
     */
    public function find(string $listCode): ?RangeSnapshotInterface
    {
        if (!array_key_exists($listCode, $this->remembered)) {
            $this->remembered[$listCode] = $this->read($listCode);
        }

        return $this->remembered[$listCode];
    }

    /**
     * @inheritDoc
     */
    public function save(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        $this->inner->save($listCode, $snapshot);
        $this->cacheSnapshot($listCode, $snapshot);
        $this->remembered[$listCode] = $snapshot;
    }

    /**
     * Snapshot of a list from the cache, or from the durable storage on a miss, which is then cached
     *
     * @param string $listCode Code of the published address list
     * @return RangeSnapshotInterface|null Null when the list was never stored
     */
    private function read(string $listCode): ?RangeSnapshotInterface
    {
        $cached = $this->cache->load($this->key($listCode));
        if ($cached === self::NEVER_STORED) {
            return null;
        }
        $snapshot = is_string($cached) ? $this->decode($cached) : null;
        if ($snapshot !== null) {
            return $snapshot;
        }

        $snapshot = $this->inner->find($listCode);
        if ($snapshot === null) {
            $this->cache->save(
                self::NEVER_STORED,
                $this->key($listCode),
                [RangeSnapshotCache::CACHE_TAG],
                self::NEVER_STORED_LIFETIME
            );

            return null;
        }
        $this->cacheSnapshot($listCode, $snapshot);

        return $snapshot;
    }

    /**
     * Put a snapshot in the cache as the value of its list
     *
     * @param string $listCode Code of the published address list
     * @param RangeSnapshotInterface $snapshot Snapshot of the list
     * @return void
     */
    private function cacheSnapshot(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        $this->cache->save(
            $this->encode($snapshot),
            $this->key($listCode),
            [RangeSnapshotCache::CACHE_TAG],
            self::VALUE_LIFETIME
        );
    }

    /**
     * Cache key of a list
     *
     * @param string $listCode Code of the published address list
     * @return string
     */
    private function key(string $listCode): string
    {
        return self::KEY_PREFIX . $listCode;
    }

    /**
     * Cached form of a snapshot
     *
     * @param RangeSnapshotInterface $snapshot Snapshot to cache
     * @return string Header, IPv4 records, IPv6 records
     */
    private function encode(RangeSnapshotInterface $snapshot): string
    {
        $ipv4Ranges = $snapshot->getIpv4Ranges();

        return pack('NN', $snapshot->getFetchedAt(), strlen($ipv4Ranges)) . $ipv4Ranges . $snapshot->getIpv6Ranges();
    }

    /**
     * Snapshot read back from its cached form
     *
     * @param string $cached Cached value
     * @return RangeSnapshotInterface|null Null when the value is not a header followed by whole records of both
     *                                     families, or its fetch moment is not positive
     */
    private function decode(string $cached): ?RangeSnapshotInterface
    {
        $recordsLength = strlen($cached) - self::HEADER_LENGTH;
        if ($recordsLength < 0) {
            return null;
        }
        $header = unpack('NfetchedAt/Nipv4Length', $cached);
        if ($header === false) {
            return null;
        }

        $fetchedAt = $header['fetchedAt'] ?? null;
        $ipv4Length = $header['ipv4Length'] ?? null;
        if (!is_int($fetchedAt) || $fetchedAt <= 0 || !is_int($ipv4Length) || $ipv4Length > $recordsLength) {
            return null;
        }
        if ($ipv4Length % RangeSnapshotInterface::IPV4_RECORD_SIZE !== 0
            || ($recordsLength - $ipv4Length) % RangeSnapshotInterface::IPV6_RECORD_SIZE !== 0
        ) {
            return null;
        }

        return $this->snapshotFactory->create([
            'ipv4Ranges' => substr($cached, self::HEADER_LENGTH, $ipv4Length),
            'ipv6Ranges' => substr($cached, self::HEADER_LENGTH + $ipv4Length),
            'fetchedAt' => $fetchedAt,
        ]);
    }
}
