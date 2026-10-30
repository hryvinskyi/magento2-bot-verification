<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\RangeListRefresher;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListFetcher;
use Magento\Framework\App\Cache\StateInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The refresher as the container builds it - real snapshot builder, real storage behind the cache, real clock and
 * result factory - over a neutral registry and a fetcher that answers in place of the network.
 *
 * Each refresher and each repository read from is a fresh instance that remembers nothing of its own, so what is
 * found comes from the cache or the flag table.
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class RangeListRefresherTest extends TestCase
{
    private const LIST_CODE = 'example_refreshed';
    private const URL = 'https://example.com/refreshed.json';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        self::assertTrue(
            self::get(StateInterface::class)->isEnabled(RangeSnapshotCache::TYPE_IDENTIFIER),
            'The cache type is off, so a snapshot found below could not have come from the cache.'
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
     * The refresher contract is filled by the refresher
     *
     * @return void
     */
    public function testTheContainerServesTheRefresher(): void
    {
        self::assertInstanceOf(RangeListRefresher::class, self::get(RangeListRefresherInterface::class));
    }

    /**
     * A refreshed list is reported through the generated result factory and found by a fresh repository, stamped
     * with the current moment
     *
     * @return void
     */
    public function testARefreshedListIsFoundByAFreshRepository(): void
    {
        $result = self::refresher(new ScriptedRangeListFetcher([
            self::URL => ['192.0.2.0/24', '2001:db8:4801::/64'],
        ]))->refresh();

        self::assertInstanceOf(RefreshResult::class, $result);
        self::assertSame([self::LIST_CODE => 2], $result->getRefreshed());
        self::assertSame([], $result->getRejected());
        $found = self::freshRepository()->find(self::LIST_CODE);
        self::assertNotNull($found);
        self::assertTrue($found->contains(self::packed('192.0.2.77')));
        self::assertTrue($found->contains(self::packed('2001:db8:4801::9')));
        self::assertEqualsWithDelta(time(), $found->getFetchedAt(), 60);
    }

    /**
     * A later refresh whose fetch fails reports the list as rejected and leaves the stored snapshot readable
     *
     * @return void
     */
    public function testAFailedRefreshLeavesTheStoredSnapshotReadable(): void
    {
        self::refresher(new ScriptedRangeListFetcher([self::URL => ['192.0.2.0/24']]))->refresh();
        $stored = self::freshRepository()->find(self::LIST_CODE);
        self::assertNotNull($stored);

        $result = self::refresher(new ScriptedRangeListFetcher([
            self::URL => new RangeListRejectedException(__('The list could not be fetched.')),
        ]))->refresh();

        self::assertSame([self::LIST_CODE => 'The list could not be fetched.'], $result->getRejected());
        self::assertSame([], $result->getRefreshed());
        self::assertSameSnapshot($stored, self::freshRepository()->find(self::LIST_CODE));
    }

    /**
     * A later refresh that would shrink the list below half of what is stored is held back against the stored
     * snapshot, which stays readable
     *
     * @return void
     */
    public function testAShrunkListIsHeldBackAgainstTheStoredSnapshot(): void
    {
        self::refresher(new ScriptedRangeListFetcher([
            self::URL => ['192.0.2.1/32', '192.0.2.3/32', '192.0.2.5/32', '192.0.2.7/32', '192.0.2.9/32'],
        ]))->refresh();
        $stored = self::freshRepository()->find(self::LIST_CODE);
        self::assertNotNull($stored);

        $result = self::refresher(new ScriptedRangeListFetcher([self::URL => ['192.0.2.1/32', '192.0.2.3/32']]))
            ->refresh();

        self::assertSame(
            [
                self::LIST_CODE => 'The list shrank from 5 to 2 ranges; the previous list is kept. '
                    . 'Refresh with --force to accept it.',
            ],
            $result->getRejected()
        );
        self::assertSameSnapshot($stored, self::freshRepository()->find(self::LIST_CODE));
    }

    /**
     * Assert that a snapshot read back holds what an earlier one held
     *
     * @param RangeSnapshotInterface $expected Snapshot read earlier
     * @param RangeSnapshotInterface|null $actual Snapshot read now
     * @return void
     */
    private static function assertSameSnapshot(RangeSnapshotInterface $expected, ?RangeSnapshotInterface $actual): void
    {
        self::assertNotNull($actual, 'The stored snapshot is no longer readable.');
        self::assertSame($expected->getFetchedAt(), $actual->getFetchedAt());
        self::assertSame($expected->getIpv4Ranges(), $actual->getIpv4Ranges());
        self::assertSame($expected->getIpv6Ranges(), $actual->getIpv6Ranges());
    }

    /**
     * A fresh refresher as the container builds it, over a neutral registry of one list and the given fetcher
     *
     * @param ScriptedRangeListFetcher $fetcher Answers in place of the network
     * @return RangeListRefresher
     */
    private static function refresher(ScriptedRangeListFetcher $fetcher): RangeListRefresher
    {
        $objectManager = Bootstrap::getObjectManager();
        $registry = $objectManager->create(CrawlerRegistry::class, [
            'crawlers' => [
                'example' => [
                    'tokens' => ['examplebot' => 'ExampleBot'],
                    'lists' => [self::LIST_CODE => self::LIST_CODE],
                ],
            ],
            'rangeLists' => [self::LIST_CODE => self::URL],
            'rangeListFormats' => [],
            'proxyLists' => [],
        ]);
        $refresher = $objectManager->create(RangeListRefresher::class, [
            'registry' => $registry,
            'fetcher' => $fetcher,
            'snapshots' => self::freshRepository(),
        ]);
        self::assertInstanceOf(RangeListRefresher::class, $refresher);

        return $refresher;
    }

    /**
     * A fresh snapshot repository as the container builds it, with its configured durable storage and cache
     *
     * @return CachedRangeSnapshotRepository
     */
    private static function freshRepository(): CachedRangeSnapshotRepository
    {
        $repository = Bootstrap::getObjectManager()->create(CachedRangeSnapshotRepository::class);
        self::assertInstanceOf(CachedRangeSnapshotRepository::class, $repository);

        return $repository;
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
