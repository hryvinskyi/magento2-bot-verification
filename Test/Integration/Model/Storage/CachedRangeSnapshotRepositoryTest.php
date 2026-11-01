<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Storage;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Model\Storage\FlagRangeSnapshotRepository;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\DataObject;
use Magento\Framework\FlagManager;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot repository as the container builds it: the cache decorator over the flag table, with the module's
 * own cache type.
 *
 * Each "fresh" decorator is a new instance that remembers nothing of its own, so what it finds comes from the cache
 * or the flag table. Removing the flag row after a value was cached proves the value came from the cache.
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class CachedRangeSnapshotRepositoryTest extends TestCase
{
    private const LIST_CODE = 'example_main';

    /**
     * Code of the flag row that holds the list
     */
    private const FLAG_CODE = 'hryvinskyi_bot_verification_example_main';

    private const FETCHED_AT = 1_700_000_000;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        self::assertTrue(
            self::get(StateInterface::class)->isEnabled(RangeSnapshotCache::TYPE_IDENTIFIER),
            'The cache type is off, so every cache assertion below would prove nothing.'
        );
        self::get(RangeSnapshotCache::class)->clean();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        self::get(RangeSnapshotCache::class)->clean();
    }

    /**
     * The repository contract is filled by the cache decorator, and the cache type is known to the application under
     * its tag, so enabling, disabling and cleaning it reach this cache
     *
     * @return void
     */
    public function testTheContainerServesTheCachedRepository(): void
    {
        self::assertInstanceOf(
            CachedRangeSnapshotRepository::class,
            Bootstrap::getObjectManager()->get(RangeSnapshotRepositoryInterface::class)
        );

        $types = self::get(TypeListInterface::class)->getTypes();
        $type = $types[RangeSnapshotCache::TYPE_IDENTIFIER] ?? null;
        self::assertInstanceOf(DataObject::class, $type, 'The application does not know the cache type.');
        self::assertSame(RangeSnapshotCache::CACHE_TAG, $type->getData('tags'));
    }

    /**
     * A snapshot saved through the decorator is found by a fresh one
     *
     * @return void
     */
    public function testFindsWhatWasSaved(): void
    {
        $saved = self::build(['203.0.113.0/27', '2001:db8:4801::/64'], self::FETCHED_AT);

        self::decorator()->save(self::LIST_CODE, $saved);
        $found = self::decorator()->find(self::LIST_CODE);

        self::assertNotNull($found);
        self::assertSame($saved->getIpv4Ranges(), $found->getIpv4Ranges());
        self::assertSame($saved->getIpv6Ranges(), $found->getIpv6Ranges());
        self::assertSame(self::FETCHED_AT, $found->getFetchedAt());
    }

    /**
     * A snapshot read once from the flag table is served from the cache afterwards, even once the row is gone
     *
     * @return void
     */
    public function testAReadSnapshotIsServedFromTheCache(): void
    {
        $stored = self::build(['198.51.100.0/24'], self::FETCHED_AT);
        self::flagStorage()->save(self::LIST_CODE, $stored);
        $readFromTheTable = self::decorator()->find(self::LIST_CODE);
        self::assertNotNull($readFromTheTable);

        self::get(FlagManager::class)->deleteFlag(self::FLAG_CODE);
        $found = self::decorator()->find(self::LIST_CODE);

        self::assertNull(self::flagStorage()->find(self::LIST_CODE));
        self::assertNotNull($found);
        self::assertSame($stored->getIpv4Ranges(), $found->getIpv4Ranges());
        self::assertSame(self::FETCHED_AT, $found->getFetchedAt());
    }

    /**
     * A save writes the new snapshot through to the cache over the one cached before: a fresh decorator finds the
     * new snapshot after its row is gone, which only the cache can give
     *
     * @return void
     */
    public function testASaveWritesThroughToTheCache(): void
    {
        self::flagStorage()->save(self::LIST_CODE, self::build(['198.51.100.0/24'], self::FETCHED_AT));
        self::assertSame(self::FETCHED_AT, self::decorator()->find(self::LIST_CODE)?->getFetchedAt());

        $newer = self::build(['203.0.113.0/27'], self::FETCHED_AT + 3600);
        self::decorator()->save(self::LIST_CODE, $newer);
        self::get(FlagManager::class)->deleteFlag(self::FLAG_CODE);
        $found = self::decorator()->find(self::LIST_CODE);

        self::assertNotNull($found);
        self::assertSame(self::FETCHED_AT + 3600, $found->getFetchedAt());
        self::assertSame($newer->getIpv4Ranges(), $found->getIpv4Ranges());
    }

    /**
     * A list found never stored is remembered as such - a row written behind the decorator's back is not seen - until
     * a save through the decorator, which a fresh decorator sees at once
     *
     * @return void
     */
    public function testANeverStoredListIsRememberedUntilSaved(): void
    {
        self::assertNull(self::decorator()->find(self::LIST_CODE));

        self::flagStorage()->save(self::LIST_CODE, self::build(['192.0.2.0/24'], self::FETCHED_AT));
        self::assertNull(self::decorator()->find(self::LIST_CODE));

        self::decorator()->save(self::LIST_CODE, self::build(['203.0.113.0/27'], self::FETCHED_AT + 60));
        self::assertSame(self::FETCHED_AT + 60, self::decorator()->find(self::LIST_CODE)?->getFetchedAt());
    }

    /**
     * The entries the decorator writes carry the cache type's tag, added by the cache type itself: cleaning the
     * application cache by that tag removes a saved snapshot, so once its row is gone too a fresh decorator finds the
     * list never stored
     *
     * @return void
     */
    public function testCleaningTheApplicationCacheByTheTypeTagRemovesTheEntries(): void
    {
        self::decorator()->save(self::LIST_CODE, self::build(['203.0.113.0/27'], self::FETCHED_AT));
        self::get(FlagManager::class)->deleteFlag(self::FLAG_CODE);
        self::assertSame(
            self::FETCHED_AT,
            self::decorator()->find(self::LIST_CODE)?->getFetchedAt(),
            'The saved snapshot was not cached, so cleaning the cache would prove nothing.'
        );

        self::get(CacheInterface::class)->clean([RangeSnapshotCache::CACHE_TAG]);

        self::assertNull(self::decorator()->find(self::LIST_CODE));
    }

    /**
     * A fresh cache decorator as the container builds it, with its configured durable storage and cache
     *
     * @return CachedRangeSnapshotRepository
     */
    private static function decorator(): CachedRangeSnapshotRepository
    {
        $decorator = Bootstrap::getObjectManager()->create(CachedRangeSnapshotRepository::class);
        self::assertInstanceOf(CachedRangeSnapshotRepository::class, $decorator);

        return $decorator;
    }

    /**
     * The durable storage alone, without the cache in front of it
     *
     * @return FlagRangeSnapshotRepository
     */
    private static function flagStorage(): FlagRangeSnapshotRepository
    {
        return self::get(FlagRangeSnapshotRepository::class);
    }

    /**
     * Snapshot of the given prefixes, built by the container's snapshot builder
     *
     * @param list<string> $prefixes CIDR prefixes
     * @param int $fetchedAt Moment the list was fetched
     * @return RangeSnapshotInterface
     */
    private static function build(array $prefixes, int $fetchedAt): RangeSnapshotInterface
    {
        return self::get(RangeSnapshotBuilderInterface::class)->build($prefixes, $fetchedAt);
    }

    /**
     * The shared instance of a type, as the container builds it
     *
     * @template T of object
     * @param class-string<T> $type
     * @return T
     */
    private static function get(string $type): object
    {
        $instance = Bootstrap::getObjectManager()->get($type);
        self::assertInstanceOf($type, $instance);

        return $instance;
    }
}
