<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Storage;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\InMemoryCacheFrontend;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use PHPUnit\Framework\TestCase;

/**
 * The cache in front of the durable storage: a value read once is served from the cache, a list never stored is
 * remembered for a short while, an unreadable cached value falls through to the durable storage, and a save writes
 * the durable storage first and then the cache, never removing an entry.
 *
 * Each test builds decorators over one shared in-memory cache and one shared in-memory durable storage; a "fresh"
 * decorator is a new instance that remembers nothing of its own.
 */
class CachedRangeSnapshotRepositoryTest extends TestCase
{
    private const LIST_CODE = 'example_main';
    private const FETCHED_AT = 1_700_000_000;
    private const VALUE_LIFETIME = 86400;
    private const NEVER_STORED_LIFETIME = 300;

    private InMemoryCacheFrontend $cache;
    private InMemoryRangeSnapshotRepository $durable;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $this->cache = new InMemoryCacheFrontend();
        $this->durable = new InMemoryRangeSnapshotRepository();
    }

    /**
     * A snapshot read from the durable storage is cached for a day, and a fresh decorator gets it back from the
     * cache with the same records and fetch moment, without asking the durable storage
     *
     * @dataProvider snapshots
     * @param string $ipv4Ranges Packed IPv4 records
     * @param string $ipv6Ranges Packed IPv6 records
     * @return void
     */
    public function testAReadSnapshotIsServedFromTheCache(string $ipv4Ranges, string $ipv6Ranges): void
    {
        $this->durable->save(self::LIST_CODE, new RangeSnapshot($ipv4Ranges, $ipv6Ranges, self::FETCHED_AT));

        $first = $this->decorator()->find(self::LIST_CODE);
        $second = $this->decorator()->find(self::LIST_CODE);

        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertSame($ipv4Ranges, $second->getIpv4Ranges());
        self::assertSame($ipv6Ranges, $second->getIpv6Ranges());
        self::assertSame(self::FETCHED_AT, $second->getFetchedAt());
        self::assertSame([self::LIST_CODE], $this->durable->asked);
        self::assertSame([self::VALUE_LIFETIME], $this->lifetimes());
    }

    /**
     * Snapshots with both families, and with one family empty
     *
     * @return array<string, array{string, string}>
     */
    public static function snapshots(): array
    {
        $ipv4 = self::record('192.0.2.0', '192.0.2.255') . self::record('203.0.113.0', '203.0.113.31');
        $ipv6 = self::record('2001:db8:4801::', '2001:db8:4801::ffff:ffff:ffff:ffff');

        return [
            'both families' => [$ipv4, $ipv6],
            'IPv6 only' => ['', $ipv6],
            'IPv4 only' => [$ipv4, ''],
        ];
    }

    /**
     * The same list asked for twice from one decorator is loaded from the cache once
     *
     * @return void
     */
    public function testOneDecoratorLoadsAListOnce(): void
    {
        $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        $decorator = $this->decorator();

        $first = $decorator->find(self::LIST_CODE);
        $second = $decorator->find(self::LIST_CODE);

        self::assertSame($first, $second);
        self::assertCount(1, $this->cache->loaded);
        self::assertSame([self::LIST_CODE], $this->durable->asked);
    }

    /**
     * A list that was never stored is remembered as such for five minutes: a fresh decorator gets null without
     * asking the durable storage again
     *
     * @return void
     */
    public function testANeverStoredListIsRememberedBriefly(): void
    {
        self::assertNull($this->decorator()->find(self::LIST_CODE));
        self::assertNull($this->decorator()->find(self::LIST_CODE));

        self::assertSame([self::LIST_CODE], $this->durable->asked);
        self::assertSame([self::NEVER_STORED_LIFETIME], $this->lifetimes());
    }

    /**
     * Lists are cached apart: each fresh decorator gets the snapshot of the list it asks for
     *
     * @return void
     */
    public function testListsAreCachedApart(): void
    {
        $this->durable->save('example_main', $this->snapshot(self::FETCHED_AT));
        $this->durable->save('example_extra', $this->snapshot(self::FETCHED_AT + 1));
        $this->decorator()->find('example_main');
        $this->decorator()->find('example_extra');

        $main = $this->decorator()->find('example_main');
        $extra = $this->decorator()->find('example_extra');

        self::assertSame(self::FETCHED_AT, $main?->getFetchedAt());
        self::assertSame(self::FETCHED_AT + 1, $extra?->getFetchedAt());
        self::assertSame(['example_main', 'example_extra'], $this->durable->asked);
    }

    /**
     * A cached value that cannot be read is a miss: the durable storage is asked, and its snapshot is cached again
     * in a form the next decorator reads
     *
     * @dataProvider unreadableValues
     * @param string $value Cached value
     * @return void
     */
    public function testAnUnreadableCachedValueFallsThroughToTheDurableStorage(string $value): void
    {
        $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        $this->decorator()->find(self::LIST_CODE);
        $this->cache->replaceAllData($value);

        $found = $this->decorator()->find(self::LIST_CODE);
        $again = $this->decorator()->find(self::LIST_CODE);

        self::assertSame(self::FETCHED_AT, $found?->getFetchedAt());
        self::assertSame(self::FETCHED_AT, $again?->getFetchedAt());
        self::assertSame([self::LIST_CODE, self::LIST_CODE], $this->durable->asked);
    }

    /**
     * Cached values that are not a header followed by whole records of both families with a positive fetch moment
     *
     * @return array<string, array{string}>
     */
    public static function unreadableValues(): array
    {
        $ipv4Record = self::record('192.0.2.0', '192.0.2.255');

        return [
            'empty' => [''],
            'shorter than the header' => ['abc'],
            'fetch moment zero' => [pack('NN', 0, 8) . $ipv4Record],
            'IPv4 length past the end' => [pack('NN', self::FETCHED_AT, 16) . $ipv4Record],
            'IPv4 length not whole records' => [pack('NN', self::FETCHED_AT, 4) . substr($ipv4Record, 0, 4)],
            'IPv6 part not whole records' => [pack('NN', self::FETCHED_AT, 8) . $ipv4Record . str_repeat("\x20", 16)],
        ];
    }

    /**
     * A save writes the durable storage first, then caches the snapshot for a day, and removes nothing
     *
     * @return void
     */
    public function testASaveWritesTheDurableStorageThenTheCache(): void
    {
        $snapshot = $this->snapshot(self::FETCHED_AT);
        $cache = $this->cache;
        $durable = $this->createMock(RangeSnapshotRepositoryInterface::class);
        $durable->expects(self::once())->method('save')->with(self::LIST_CODE, $snapshot)->willReturnCallback(
            static function () use ($cache): void {
                self::assertSame([], $cache->saved, 'The cache was written before the durable storage.');
            }
        );

        $this->decorator($durable)->save(self::LIST_CODE, $snapshot);

        self::assertCount(1, $this->cache->saved);
        self::assertSame([], $this->cache->removed);
        self::assertSame([self::VALUE_LIFETIME], $this->lifetimes());
        self::assertSame([[RangeSnapshotCache::CACHE_TAG]], $this->tags());
    }

    /**
     * After a save, a fresh decorator finds the saved snapshot in the cache, and the saving decorator returns it
     * without loading anything
     *
     * @return void
     */
    public function testASavedSnapshotIsFoundWithoutAskingTheDurableStorage(): void
    {
        $snapshot = $this->snapshot(self::FETCHED_AT);
        $decorator = $this->decorator();

        $decorator->save(self::LIST_CODE, $snapshot);

        self::assertSame($snapshot, $decorator->find(self::LIST_CODE));
        self::assertSame([], $this->cache->loaded);
        self::assertSame(self::FETCHED_AT, $this->decorator()->find(self::LIST_CODE)?->getFetchedAt());
        self::assertSame([], $this->durable->asked);
    }

    /**
     * A save replaces the cached value of a list, and the marker of a list never stored, so the very next read sees
     * the new snapshot
     *
     * @dataProvider cachedBeforeTheSave
     * @param bool $storedBefore Whether the list held a snapshot before the save
     * @return void
     */
    public function testASaveReplacesWhatWasCached(bool $storedBefore): void
    {
        if ($storedBefore) {
            $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        }
        $this->decorator()->find(self::LIST_CODE);

        $this->decorator()->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT + 3600));

        self::assertSame(self::FETCHED_AT + 3600, $this->decorator()->find(self::LIST_CODE)?->getFetchedAt());
        self::assertSame([self::LIST_CODE], $this->durable->asked);
        self::assertSame([], $this->cache->removed);
    }

    /**
     * A cached snapshot, and the marker of a list never stored
     *
     * @return array<string, array{bool}>
     */
    public static function cachedBeforeTheSave(): array
    {
        return [
            'a snapshot was cached' => [true],
            'the list was remembered as never stored' => [false],
        ];
    }

    /**
     * A save the durable storage refuses reaches neither the cache nor what the decorator remembers
     *
     * @return void
     */
    public function testAFailedDurableSaveLeavesTheCacheAlone(): void
    {
        $durable = $this->createMock(RangeSnapshotRepositoryInterface::class);
        $durable->method('find')->willReturn(null);
        $durable->method('save')->willThrowException(new \RuntimeException('The storage is unavailable.'));
        $decorator = $this->decorator($durable);
        $decorator->find(self::LIST_CODE);
        $savesBefore = $this->cache->saved;

        try {
            $decorator->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
            self::fail('The failure of the durable storage was swallowed.');
        } catch (\RuntimeException $exception) {
            self::assertSame('The storage is unavailable.', $exception->getMessage());
        }

        self::assertSame($savesBefore, $this->cache->saved);
        self::assertNull($decorator->find(self::LIST_CODE));
    }

    /**
     * A decorator over the shared cache, the given durable storage or the shared in-memory one, and a factory that
     * builds a real snapshot from its arguments
     *
     * @param RangeSnapshotRepositoryInterface|null $durable Durable storage; the shared in-memory one when null
     * @return CachedRangeSnapshotRepository
     */
    private function decorator(?RangeSnapshotRepositoryInterface $durable = null): CachedRangeSnapshotRepository
    {
        $factory = $this->createMock(RangeSnapshotInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $data): RangeSnapshotInterface {
                $ipv4Ranges = $data['ipv4Ranges'] ?? null;
                $ipv6Ranges = $data['ipv6Ranges'] ?? null;
                $fetchedAt = $data['fetchedAt'] ?? null;
                self::assertIsString($ipv4Ranges);
                self::assertIsString($ipv6Ranges);
                self::assertIsInt($fetchedAt);

                return new RangeSnapshot($ipv4Ranges, $ipv6Ranges, $fetchedAt);
            }
        );

        return new CachedRangeSnapshotRepository($durable ?? $this->durable, $this->cache, $factory);
    }

    /**
     * A snapshot of one IPv4 and one IPv6 range
     *
     * @param int $fetchedAt Moment the list was fetched
     * @return RangeSnapshotInterface
     */
    private function snapshot(int $fetchedAt): RangeSnapshotInterface
    {
        return new RangeSnapshot(
            self::record('198.51.100.0', '198.51.100.255'),
            self::record('2001:db8:4801::', '2001:db8:4801::ffff:ffff:ffff:ffff'),
            $fetchedAt
        );
    }

    /**
     * Lifetimes of the cache entries, in the order they were first written
     *
     * @return list<int|bool|null>
     */
    private function lifetimes(): array
    {
        return array_values(array_map(
            static fn (array $entry): int|bool|null => $entry['lifetime'],
            $this->cache->entries
        ));
    }

    /**
     * Tags of the cache entries, in the order they were first written
     *
     * @return list<array<mixed>>
     */
    private function tags(): array
    {
        return array_values(array_map(static fn (array $entry): array => $entry['tags'], $this->cache->entries));
    }

    /**
     * One packed record of a range
     *
     * @param string $start First address as text
     * @param string $end Last address as text
     * @return string
     */
    private static function record(string $start, string $end): string
    {
        return self::packed($start) . self::packed($end);
    }

    /**
     * Packed form of an address written as text
     *
     * @param string $address Valid address
     * @return string 4 or 16 bytes
     */
    private static function packed(string $address): string
    {
        $packed = inet_pton($address);
        self::assertIsString($packed, $address . ' is not a valid address');

        return $packed;
    }
}
