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
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\InMemoryCacheFrontend;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use PHPUnit\Framework\TestCase;

/**
 * The cache in front of the durable storage: a value read once is served from the cache, a list never stored is
 * remembered for a short while, an unreadable cached value falls through to the durable storage, and a save writes
 * the durable storage first and then the cache. A value read from the durable storage never replaces a newer one a
 * save cached meanwhile, and a cache fault never fails a read nor hides a durable save.
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
     * The same list asked for twice from one decorator is read once: the second ask touches neither the cache nor
     * the durable storage
     *
     * @return void
     */
    public function testOneDecoratorReadsAListOnce(): void
    {
        $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        $decorator = $this->decorator();

        $first = $decorator->find(self::LIST_CODE);
        $loadsAfterTheFirstAsk = $this->cache->loaded;
        $second = $decorator->find(self::LIST_CODE);

        self::assertSame($first, $second);
        self::assertSame($loadsAfterTheFirstAsk, $this->cache->loaded);
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
     * A save that lands while a reader is between its read of the durable storage and its cache fill wins: the
     * reader returns the snapshot the save cached, as new as or newer than its own, and writes nothing over it
     *
     * @dataProvider racingSavesNoOlderThanTheRead
     * @param int $racingFetchedAt Fetch moment of the snapshot the racing save stores
     * @return void
     */
    public function testASnapshotSavedDuringARead(int $racingFetchedAt): void
    {
        $racing = $this->otherSnapshot($racingFetchedAt);
        $reader = $this->decorator($this->racingDurable($this->snapshot(self::FETCHED_AT), $racing));

        $found = $reader->find(self::LIST_CODE);

        self::assertNotNull($found);
        self::assertSame($racing->getIpv4Ranges(), $found->getIpv4Ranges());
        self::assertSame($racingFetchedAt, $found->getFetchedAt());
        self::assertCount(1, $this->cache->saved, 'The reader wrote over the snapshot the save cached.');
        $again = $this->decorator()->find(self::LIST_CODE);
        self::assertNotNull($again);
        self::assertSame($racing->getIpv4Ranges(), $again->getIpv4Ranges());
        self::assertSame($racingFetchedAt, $again->getFetchedAt());
    }

    /**
     * Racing saves of a snapshot newer than the one read, and of one fetched at the same moment
     *
     * @return array<string, array{int}>
     */
    public static function racingSavesNoOlderThanTheRead(): array
    {
        return [
            'newer' => [self::FETCHED_AT + 3600],
            'fetched at the same moment' => [self::FETCHED_AT],
        ];
    }

    /**
     * A reader that finds an older snapshot cached when it is about to fill the cache replaces it with the newer one
     * it read
     *
     * @return void
     */
    public function testAReaderReplacesAnOlderCachedSnapshot(): void
    {
        $read = $this->snapshot(self::FETCHED_AT + 3600);
        $reader = $this->decorator($this->racingDurable($read, $this->otherSnapshot(self::FETCHED_AT)));

        $found = $reader->find(self::LIST_CODE);

        self::assertSame($read, $found);
        $again = $this->decorator()->find(self::LIST_CODE);
        self::assertNotNull($again);
        self::assertSame($read->getIpv4Ranges(), $again->getIpv4Ranges());
        self::assertSame(self::FETCHED_AT + 3600, $again->getFetchedAt());
    }

    /**
     * A first save that lands while a reader is between finding the list never stored and caching that fact wins:
     * the reader returns the saved snapshot, and the "never stored" marker does not replace it
     *
     * @return void
     */
    public function testAFirstSaveDuringAReadIsNotHiddenByTheNeverStoredMarker(): void
    {
        $racing = $this->snapshot(self::FETCHED_AT);
        $reader = $this->decorator($this->racingDurable(null, $racing));

        $found = $reader->find(self::LIST_CODE);

        self::assertSame(self::FETCHED_AT, $found?->getFetchedAt());
        self::assertCount(1, $this->cache->saved, 'The reader wrote the marker over the snapshot the save cached.');
        self::assertSame(self::FETCHED_AT, $this->decorator()->find(self::LIST_CODE)?->getFetchedAt());
        self::assertSame([], $this->durable->asked);
    }

    /**
     * A list never stored is still remembered as such when the cache holds a value nobody can read
     *
     * @return void
     */
    public function testTheNeverStoredMarkerReplacesAnUnreadableValue(): void
    {
        $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        $this->decorator()->find(self::LIST_CODE);
        $this->cache->replaceAllData('abc');
        $durable = $this->createMock(RangeSnapshotRepositoryInterface::class);
        $durable->method('find')->willReturn(null);

        self::assertNull($this->decorator($durable)->find(self::LIST_CODE));

        self::assertNull($this->decorator()->find(self::LIST_CODE));
        self::assertSame([self::LIST_CODE], $this->durable->asked);
    }

    /**
     * A cache that fails while a list is read never fails the read: a failed load is a miss, the durable storage is
     * asked, and a failed fill still returns what the durable storage gave
     *
     * @dataProvider faultsWhileReading
     * @param bool $loadFails Whether loading from the cache throws
     * @param bool $fillFails Whether saving into the cache throws
     * @param bool $stored Whether the list holds a snapshot in the durable storage
     * @return void
     */
    public function testACacheFaultWhileReadingFallsThroughToTheDurableStorage(
        bool $loadFails,
        bool $fillFails,
        bool $stored
    ): void {
        if ($stored) {
            $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        }
        $this->cache->loadFault = $loadFails ? new \RuntimeException('The cache backend timed out.') : null;
        $this->cache->saveFault = $fillFails ? new \RuntimeException('The cache backend timed out.') : null;

        $found = $this->decorator()->find(self::LIST_CODE);

        self::assertSame($stored ? self::FETCHED_AT : null, $found?->getFetchedAt());
        self::assertSame([self::LIST_CODE], $this->durable->asked);
    }

    /**
     * Which cache calls fail, for a list that was stored and one that was never stored
     *
     * @return array<string, array{bool, bool, bool}>
     */
    public static function faultsWhileReading(): array
    {
        return [
            'load fails, list stored' => [true, false, true],
            'load fails, list never stored' => [true, false, false],
            'fill fails, list stored' => [false, true, true],
            'fill fails, list never stored' => [false, true, false],
            'both fail, list stored' => [true, true, true],
            'both fail, list never stored' => [true, true, false],
        ];
    }

    /**
     * A cache that fails to take the new snapshot of a save does not hide the durable save: the save succeeds, the
     * saving decorator returns the new snapshot, and the list's old cached value is removed, so a fresh decorator
     * reads the new snapshot from the durable storage
     *
     * @dataProvider writeThroughFaults
     * @param bool $saveThrows Whether the cache save throws rather than reporting failure
     * @return void
     */
    public function testAFailedWriteThroughRemovesTheOldCachedValue(bool $saveThrows): void
    {
        $this->durable->save(self::LIST_CODE, $this->snapshot(self::FETCHED_AT));
        $this->decorator()->find(self::LIST_CODE);
        $newer = $this->snapshot(self::FETCHED_AT + 3600);
        $this->cache->saveFault = $saveThrows ? new \RuntimeException('The cache backend timed out.') : null;
        $this->cache->rejectSaves = !$saveThrows;
        $saver = $this->decorator();

        $saver->save(self::LIST_CODE, $newer);

        self::assertSame([self::LIST_CODE, self::LIST_CODE], $this->durable->saved);
        self::assertSame($newer, $saver->find(self::LIST_CODE));
        self::assertCount(1, $this->cache->removed);
        $this->cache->saveFault = null;
        $this->cache->rejectSaves = false;
        self::assertSame(self::FETCHED_AT + 3600, $this->decorator()->find(self::LIST_CODE)?->getFetchedAt());
    }

    /**
     * A cache save that throws, and one that reports failure
     *
     * @return array<string, array{bool}>
     */
    public static function writeThroughFaults(): array
    {
        return [
            'the save throws' => [true],
            'the save reports failure' => [false],
        ];
    }

    /**
     * A cache that cannot even remove the old value after a failed write-through still does not fail the save
     *
     * @return void
     */
    public function testAFailedRemovalAfterAFailedWriteThroughIsSwallowed(): void
    {
        $newer = $this->snapshot(self::FETCHED_AT + 3600);
        $this->cache->saveFault = new \RuntimeException('The cache backend timed out.');
        $this->cache->removeFault = new \RuntimeException('The cache backend timed out.');
        $saver = $this->decorator();

        $saver->save(self::LIST_CODE, $newer);

        self::assertSame([self::LIST_CODE], $this->durable->saved);
        self::assertSame($newer, $saver->find(self::LIST_CODE));
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
     * A durable storage that answers a read with the given snapshot, and right after that read - before the reader
     * can fill the cache - lets a save of another snapshot go through a separate decorator over the shared cache and
     * the shared durable storage, as a refresh running at the same moment would
     *
     * @param RangeSnapshotInterface|null $read What the read returns; null when the list was never stored
     * @param RangeSnapshotInterface $racing Snapshot the racing save stores
     * @return RangeSnapshotRepositoryInterface
     */
    private function racingDurable(
        ?RangeSnapshotInterface $read,
        RangeSnapshotInterface $racing
    ): RangeSnapshotRepositoryInterface {
        $durable = $this->createMock(RangeSnapshotRepositoryInterface::class);
        $durable->expects(self::once())->method('find')->with(self::LIST_CODE)->willReturnCallback(
            function () use ($read, $racing): ?RangeSnapshotInterface {
                $this->decorator()->save(self::LIST_CODE, $racing);

                return $read;
            }
        );

        return $durable;
    }

    /**
     * A snapshot of one IPv4 range other than the one of snapshot(), so the two can be told apart
     *
     * @param int $fetchedAt Moment the list was fetched
     * @return RangeSnapshotInterface
     */
    private function otherSnapshot(int $fetchedAt): RangeSnapshotInterface
    {
        return new RangeSnapshot(self::record('203.0.113.0', '203.0.113.31'), '', $fetchedAt);
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
