<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Address;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Model\Address\TrustedProxyRanges;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Hryvinskyi\BotVerification\Model\Range\SnapshotFreshness;
use Hryvinskyi\BotVerification\Test\Double\FixedConfig;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use PHPUnit\Framework\TestCase;

/**
 * The shop's own proxies come from the configured ranges alone, without I/O; an edge server is an address a declared
 * proxy list holds, fresh or stale, and an address in no list is ruled out only when every list is present and fresh.
 *
 * Proxy list "example_edge" holds 192.0.2.0/24 and "example_edge_v6" holds 3fff:100::/32 unless a test seeds them
 * differently.
 */
class TrustedProxyRangesTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const MAX_AGE = 604800;
    private const EDGE_LIST = 'example_edge';
    private const EDGE_LIST_V6 = 'example_edge_v6';

    /**
     * An address in a configured range is an own proxy, and no proxy list is read for it
     *
     * @dataProvider configuredAddresses
     * @param string $address Address in a configured range
     * @return void
     */
    public function testAnAddressInAConfiguredRangeIsAnOwnProxy(string $address): void
    {
        $snapshots = $this->snapshots();

        $isOwnProxy = $this->trustedProxies(['10.0.0.0/8', '::1/128'], $snapshots)->isOwnProxy($this->pack($address));

        self::assertTrue($isOwnProxy);
        self::assertSame([], $snapshots->asked);
    }

    /**
     * Addresses of both families inside the configured ranges
     *
     * @return array<string, array{string}>
     */
    public static function configuredAddresses(): array
    {
        return [
            'IPv4' => ['10.20.30.40'],
            'IPv6' => ['::1'],
        ];
    }

    /**
     * An address outside the configured ranges is not an own proxy, even when a proxy list holds it, and no proxy
     * list is read for it
     *
     * @return void
     */
    public function testAnAddressOutsideTheConfiguredRangesIsNotAnOwnProxy(): void
    {
        $snapshots = $this->snapshots();

        $isOwnProxy = $this->trustedProxies(['10.0.0.0/8'], $snapshots)->isOwnProxy($this->pack('192.0.2.10'));

        self::assertFalse($isOwnProxy);
        self::assertSame([], $snapshots->asked);
    }

    /**
     * A configured line that is not a range makes every own-proxy answer unknown, for addresses inside the valid
     * lines too
     *
     * @return void
     */
    public function testAnInvalidConfiguredLineMakesEveryAnswerUnknown(): void
    {
        $trustedProxies = $this->trustedProxies(['10.0.0.0/8', 'not a range'], $this->snapshots());

        self::assertNull($trustedProxies->isOwnProxy($this->pack('10.0.0.5')));
        self::assertNull($trustedProxies->isOwnProxy($this->pack('198.51.100.7')));
    }

    /**
     * An empty configuration holds no range and never reaches the builder, which refuses an empty list
     *
     * @return void
     */
    public function testAnEmptyConfigurationTrustsNothing(): void
    {
        $builder = $this->createMock(RangeSnapshotBuilderInterface::class);
        $builder->expects(self::never())->method('build');
        $trustedProxies = new TrustedProxyRanges(
            new FixedConfig([]),
            $builder,
            $this->registry([self::EDGE_LIST]),
            $this->snapshots(),
            $this->freshness(),
            new FixedTimeSource(self::NOW)
        );

        self::assertFalse($trustedProxies->isOwnProxy($this->pack('127.0.0.1')));
    }

    /**
     * The configured ranges are read and built once per instance
     *
     * @return void
     */
    public function testTheConfiguredRangesAreReadOnce(): void
    {
        $config = new FixedConfig(['10.0.0.0/8']);
        $trustedProxies = new TrustedProxyRanges(
            $config,
            $this->builder(),
            $this->registry([]),
            $this->snapshots(),
            $this->freshness(),
            new FixedTimeSource(self::NOW)
        );

        $trustedProxies->isOwnProxy($this->pack('10.0.0.5'));
        $trustedProxies->isOwnProxy($this->pack('198.51.100.7'));

        self::assertSame(1, $config->trustedProxyReads);
    }

    /**
     * An address in a fresh proxy list is an edge server
     *
     * @return void
     */
    public function testAnAddressInAFreshProxyListIsAnEdgeServer(): void
    {
        $isEdgeProxy = $this->trustedProxies([], $this->snapshots())->isEdgeProxy($this->pack('192.0.2.10'));

        self::assertTrue($isEdgeProxy);
    }

    /**
     * A stale proxy list still vouches for an address it holds
     *
     * @return void
     */
    public function testAStaleProxyListStillVouches(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST => ['192.0.2.0/24', self::MAX_AGE + 1]]);

        self::assertTrue($this->trustedProxies([], $snapshots)->isEdgeProxy($this->pack('192.0.2.10')));
    }

    /**
     * An address in no fresh proxy list is not an edge server
     *
     * @return void
     */
    public function testAnAddressInNoFreshListIsNotAnEdgeServer(): void
    {
        self::assertFalse($this->trustedProxies([], $this->snapshots())->isEdgeProxy($this->pack('198.51.100.7')));
    }

    /**
     * A proxy list never fetched leaves an address it would have to rule out undecided
     *
     * @return void
     */
    public function testAMissingProxyListLeavesTheAnswerUnknown(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST => null]);

        self::assertNull($this->trustedProxies([], $snapshots)->isEdgeProxy($this->pack('198.51.100.7')));
    }

    /**
     * A stale proxy list cannot rule out an address it lacks
     *
     * @return void
     */
    public function testAStaleProxyListLeavesAnAddressItLacksUnknown(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST => ['192.0.2.0/24', self::MAX_AGE + 1]]);

        self::assertNull($this->trustedProxies([], $snapshots)->isEdgeProxy($this->pack('198.51.100.7')));
    }

    /**
     * A stale list that publishes only the other family cannot speak for the address, so a fresh list of the address's
     * family that lacks it rules it out
     *
     * @return void
     */
    public function testAStaleListOfAnotherFamilyDoesNotLeaveTheAnswerUnknown(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST_V6 => ['3fff:100::/32', self::MAX_AGE + 1]]);
        $trustedProxies = $this->trustedProxies([], $snapshots, [self::EDGE_LIST, self::EDGE_LIST_V6]);

        self::assertFalse($trustedProxies->isEdgeProxy($this->pack('198.51.100.7')));
    }

    /**
     * A list never fetched may hold any family, so it leaves the answer unknown whatever the address's family
     *
     * @return void
     */
    public function testANeverFetchedListLeavesTheAnswerUnknownForEitherFamily(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST_V6 => null]);
        $trustedProxies = $this->trustedProxies([], $snapshots, [self::EDGE_LIST, self::EDGE_LIST_V6]);

        self::assertNull($trustedProxies->isEdgeProxy($this->pack('198.51.100.7')));
    }

    /**
     * A list exactly the maximum age old is still fresh enough to rule an address out
     *
     * @return void
     */
    public function testAListExactlyTheMaximumAgeOldStillRulesOut(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST => ['192.0.2.0/24', self::MAX_AGE]]);

        self::assertFalse($this->trustedProxies([], $snapshots)->isEdgeProxy($this->pack('198.51.100.7')));
    }

    /**
     * An address in the second of two proxy lists is an edge server while the first was never fetched, and both
     * lists are asked in declared order
     *
     * @return void
     */
    public function testAnAddressInTheSecondListIsAnEdgeServerWhileTheFirstIsMissing(): void
    {
        $snapshots = $this->snapshots([self::EDGE_LIST => null]);
        $trustedProxies = $this->trustedProxies([], $snapshots, [self::EDGE_LIST, self::EDGE_LIST_V6]);

        self::assertTrue($trustedProxies->isEdgeProxy($this->pack('3fff:100::7')));
        self::assertSame([self::EDGE_LIST, self::EDGE_LIST_V6], $snapshots->asked);
    }

    /**
     * An address in the first list ends the search: the lists after it are not read
     *
     * @return void
     */
    public function testTheFirstListThatHoldsTheAddressEndsTheSearch(): void
    {
        $snapshots = $this->snapshots();
        $trustedProxies = $this->trustedProxies([], $snapshots, [self::EDGE_LIST, self::EDGE_LIST_V6]);

        self::assertTrue($trustedProxies->isEdgeProxy($this->pack('192.0.2.10')));
        self::assertSame([self::EDGE_LIST], $snapshots->asked);
    }

    /**
     * With no proxy list declared no address is an edge server, and no list is read
     *
     * @return void
     */
    public function testNoDeclaredProxyListMeansNoEdgeServer(): void
    {
        $snapshots = $this->snapshots();

        self::assertFalse($this->trustedProxies([], $snapshots, [])->isEdgeProxy($this->pack('192.0.2.10')));
        self::assertSame([], $snapshots->asked);
    }

    /**
     * Trusted proxies over the given configured ranges and snapshots, the declared proxy lists, a fresh builder and
     * a clock standing at NOW
     *
     * @param list<string> $configured Configured ranges of the shop's own proxies
     * @param InMemoryRangeSnapshotRepository $snapshots Snapshots of the proxy lists
     * @param list<string> $proxyLists Codes of the declared proxy lists, in declaration order
     * @return TrustedProxyRanges
     */
    private function trustedProxies(
        array $configured,
        InMemoryRangeSnapshotRepository $snapshots,
        array $proxyLists = [self::EDGE_LIST]
    ): TrustedProxyRanges {
        return new TrustedProxyRanges(
            new FixedConfig($configured),
            $this->builder(),
            $this->registry($proxyLists),
            $snapshots,
            $this->freshness(),
            new FixedTimeSource(self::NOW)
        );
    }

    /**
     * A registry that declares the given proxy lists and no crawler
     *
     * @param list<string> $proxyLists Codes of the proxy lists, in declaration order
     * @return CrawlerRegistry
     */
    private function registry(array $proxyLists): CrawlerRegistry
    {
        $urls = [];
        $formats = [];
        foreach ($proxyLists as $listCode) {
            $urls[$listCode] = 'https://edge.example/' . $listCode . '.txt';
            $formats[$listCode] = 'plain_text';
        }

        return new CrawlerRegistry([], $urls, $formats, $proxyLists);
    }

    /**
     * A repository holding fresh snapshots of both proxy lists, with some replaced or left out
     *
     * @param array<string, array{string, int}|null> $overrides List code => [prefix, age in seconds], or null for a
     *                                                          list never fetched
     * @return InMemoryRangeSnapshotRepository
     */
    private function snapshots(array $overrides = []): InMemoryRangeSnapshotRepository
    {
        $lists = array_merge(
            [self::EDGE_LIST => ['192.0.2.0/24', 0], self::EDGE_LIST_V6 => ['3fff:100::/32', 0]],
            $overrides
        );

        $builder = $this->builder();
        $repository = new InMemoryRangeSnapshotRepository();
        foreach ($lists as $listCode => $list) {
            if ($list !== null) {
                $repository->save($listCode, $builder->build([$list[0]], self::NOW - $list[1]));
            }
        }

        return $repository;
    }

    /**
     * The freshness of snapshots judged at NOW with a week's maximum age
     *
     * @return SnapshotFreshness
     */
    private function freshness(): SnapshotFreshness
    {
        return new SnapshotFreshness(new FixedTimeSource(self::NOW), self::MAX_AGE);
    }

    /**
     * The real snapshot builder over a factory stub
     *
     * @return RangeSnapshotBuilder
     */
    private function builder(): RangeSnapshotBuilder
    {
        return new RangeSnapshotBuilder($this->snapshotFactory());
    }

    /**
     * Packed form of an address
     *
     * @param string $address Address as text
     * @return string
     */
    private function pack(string $address): string
    {
        $packed = inet_pton($address);
        self::assertIsString($packed);

        return $packed;
    }

    /**
     * A snapshot factory that builds the snapshot from the arguments it is given
     *
     * @return RangeSnapshotInterfaceFactory
     */
    private function snapshotFactory(): RangeSnapshotInterfaceFactory
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

        return $factory;
    }
}
