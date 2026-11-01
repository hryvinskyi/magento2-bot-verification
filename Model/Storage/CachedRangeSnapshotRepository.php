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
use Magento\Framework\Cache\FrontendInterface;

/**
 * Serves snapshots from the application cache in front of the durable storage
 *
 * - Key: the list code only. The ranges a list holds are the same for every store, locale and visitor.
 * - Value: the fetch moment and the length of the IPv4 records in an 8-byte header, then the IPv4 records, then the
 *   IPv6 records, as stored by the snapshot. A list that was never stored is cached as a short marker, so a list
 *   nobody has fetched yet does not send every request that names it to the durable storage. The entries are tagged
 *   by the injected cache, not here.
 * - Invalidation: a save writes the durable storage first and then the new value through to the cache, overwriting
 *   a cached value or marker, so a refresh never causes a miss. The lifetimes - a day for a value, five minutes for
 *   the marker - are a safety net, not the means of invalidation.
 * - The saver is the authority; a reader only fills a miss. A reader that missed loads the key again after reading
 *   the durable storage: a value cached meanwhile by a save, fetched at the same moment as what it read or later, is
 *   returned and left in place, and a list a save stored meanwhile is not marked as never stored. Only a save that
 *   lands between that second load and the reader's own write can still be overwritten - a window of one cache
 *   round trip, not the length of a durable read. An older value cached that way lives until the next save or for a
 *   day at most, the "never stored" marker for five minutes.
 * - A miss costs one read of the durable storage and two cache loads per process that misses; the value is cached
 *   right after. It happens after the cache is flushed, or when a list has had no successful save for a day.
 * - A cached value this class cannot read is a miss: the durable storage is read and the value cached again.
 * - A cache that fails never fails a read: a failed load is a miss, a failed fill is ignored and the snapshot read is
 *   still returned. A cache that fails to take the value of a save never hides the durable save, which has already
 *   succeeded: the list's key is then removed, best effort, so the next reader takes the new snapshot from the
 *   durable storage rather than an older cached one. Only a durable storage that fails makes a save throw.
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
        $this->remembered[$listCode] = $snapshot;
        if (!$this->store($listCode, $this->encode($snapshot), self::VALUE_LIFETIME)) {
            $this->forget($listCode);
        }
    }

    /**
     * Snapshot of a list from the cache, or from the durable storage on a miss, which is then cached unless a save
     * cached a value of its own meanwhile
     *
     * @param string $listCode Code of the published address list
     * @return RangeSnapshotInterface|null Null when the list was never stored
     */
    private function read(string $listCode): ?RangeSnapshotInterface
    {
        $cached = $this->loadCached($listCode);
        if ($cached === self::NEVER_STORED) {
            return null;
        }
        $snapshot = $cached === null ? null : $this->decode($cached);
        if ($snapshot !== null) {
            return $snapshot;
        }

        $stored = $this->inner->find($listCode);

        $cachedMeanwhile = $this->loadCached($listCode);
        $savedMeanwhile = $cachedMeanwhile === null || $cachedMeanwhile === self::NEVER_STORED
            ? null
            : $this->decode($cachedMeanwhile);
        if ($savedMeanwhile !== null
            && ($stored === null || $savedMeanwhile->getFetchedAt() >= $stored->getFetchedAt())
        ) {
            return $savedMeanwhile;
        }

        if ($stored === null) {
            $this->store($listCode, self::NEVER_STORED, self::NEVER_STORED_LIFETIME);

            return null;
        }
        $this->store($listCode, $this->encode($stored), self::VALUE_LIFETIME);

        return $stored;
    }

    /**
     * Cached value of a list
     *
     * @param string $listCode Code of the published address list
     * @return string|null Null when nothing is cached, or the cache failed to answer
     */
    private function loadCached(string $listCode): ?string
    {
        try {
            $cached = $this->cache->load($this->key($listCode));
        } catch (\Throwable) {
            return null;
        }

        return is_string($cached) ? $cached : null;
    }

    /**
     * Put a value in the cache as the value of its list
     *
     * @param string $listCode Code of the published address list
     * @param string $value Cached form of a snapshot, or the "never stored" marker
     * @param int $lifetime Seconds the value lives
     * @return bool Whether the cache took the value; false when it refused or failed
     */
    private function store(string $listCode, string $value, int $lifetime): bool
    {
        try {
            return $this->cache->save($value, $this->key($listCode), [], $lifetime);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Remove the cached value of a list, if the cache lets it
     *
     * @param string $listCode Code of the published address list
     * @return void
     */
    private function forget(string $listCode): void
    {
        try {
            $this->cache->remove($this->key($listCode));
        } catch (\Throwable) {
            return;
        }
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
