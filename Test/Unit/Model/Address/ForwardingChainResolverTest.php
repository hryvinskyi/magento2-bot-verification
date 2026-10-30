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
use Hryvinskyi\BotVerification\Model\Address\AddressParser;
use Hryvinskyi\BotVerification\Model\Address\ForwardingChainResolver;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Hryvinskyi\BotVerification\Test\Double\FixedTrustedProxyRanges;
use PHPUnit\Framework\TestCase;

/**
 * The forwarding chain is walked from the right: past the shop's own proxies any number of times, past at most one
 * edge server, and the next address is the client, which is never asked about.
 *
 * Own proxies are 127.0.0.0/8, 10.0.0.0/8 and ::1/128, edge servers 192.0.2.0/24. 198.51.100.0/24, 203.0.113.0/24 and
 * 3fff::/20 stand for visitors and whatever a client writes.
 */
class ForwardingChainResolverTest extends TestCase
{
    private const VISITOR = '198.51.100.7';
    private const EDGE = '192.0.2.10';

    /**
     * The walk's outcome for a chain
     *
     * @dataProvider chains
     * @param string $remoteAddress Connecting address
     * @param string $forwardedFor X-Forwarded-For header; '' when absent
     * @param string|null $expected The client, or null when none can be determined
     * @return void
     */
    public function testResolvesTheClientOfAChain(string $remoteAddress, string $forwardedFor, ?string $expected): void
    {
        self::assertSame($expected, $this->resolver($this->trustedProxies())->resolve($remoteAddress, $forwardedFor));
    }

    /**
     * Chains and their clients
     *
     * @return array<string, array{string, string, string|null}>
     */
    public static function chains(): array
    {
        return [
            'no proxy: a forged header is never read' => [self::VISITOR, '203.0.113.9', self::VISITOR],
            'behind an own proxy' => ['127.0.0.1', self::VISITOR, self::VISITOR],
            'behind two own proxies' => ['127.0.0.1', self::VISITOR . ', 10.0.0.5', self::VISITOR],
            'a request sent from inside the edge network: the second edge address is the client' => [
                '127.0.0.1',
                self::VISITOR . ', 192.0.2.20, ' . self::EDGE . ', 127.0.0.1',
                '192.0.2.20',
            ],
            'an own-range address left of the edge is the client' => [
                '127.0.0.1',
                '10.0.0.5, ' . self::EDGE . ', 127.0.0.1',
                '10.0.0.5',
            ],
            'the connecting address is an edge server, no own proxy' => [self::EDGE, self::VISITOR, self::VISITOR],
            'an edge server with nothing left of it' => ['127.0.0.1', self::EDGE, null],
            'only an own proxy, no header' => ['127.0.0.1', '', null],
            'only own proxies' => ['127.0.0.1', '10.0.0.5', null],
            'IPv6 behind an own proxy' => ['::1', '3fff::7', '3fff::7'],
            'IPv4-mapped connecting address read as IPv4' => ['::ffff:' . self::VISITOR, '', self::VISITOR],
            'IPv4-mapped loopback is an own proxy' => ['::ffff:127.0.0.1', self::VISITOR, self::VISITOR],
            'the client comes back normalised' => ['127.0.0.1', '3fff:0:0:0:0:0:0:7', '3fff::7'],
            'surrounding whitespace in the header is ignored' => [
                '127.0.0.1',
                '  ' . self::VISITOR . "\t",
                self::VISITOR,
            ],
            'empty connecting address' => ['', self::VISITOR, null],
        ];
    }

    /**
     * Behind an edge server with a forged entry further left, the entry the edge server appended is the client, and
     * only the address after the own proxies is asked about as an edge server - never the client
     *
     * @return void
     */
    public function testAsksOnlyTheAddressAfterTheOwnProxiesAboutTheEdge(): void
    {
        $trustedProxies = $this->trustedProxies();

        $client = $this->resolver($trustedProxies)->resolve(
            '127.0.0.1',
            '203.0.113.66, ' . self::VISITOR . ', ' . self::EDGE . ', 127.0.0.1'
        );

        self::assertSame(self::VISITOR, $client);
        self::assertSame([self::EDGE], $trustedProxies->edgeAsked);
        self::assertSame(['127.0.0.1', '127.0.0.1', self::EDGE], $trustedProxies->ownAsked);
    }

    /**
     * An address the walk has to read and cannot is the end of it
     *
     * @dataProvider unreadableEntries
     * @param string $entry Entry that is not an address
     * @return void
     */
    public function testAnUnreadableEntryThatIsReachedLeavesNoClient(string $entry): void
    {
        $resolver = $this->resolver($this->trustedProxies());

        self::assertNull($resolver->resolve('127.0.0.1', $entry), 'right after an own proxy');
        self::assertNull($resolver->resolve('127.0.0.1', $entry . ', ' . self::EDGE), 'right after an edge server');
    }

    /**
     * An entry that is not an address, left of the client, is never read
     *
     * @dataProvider unreadableEntries
     * @param string $entry Entry that is not an address
     * @return void
     */
    public function testAnUnreadableEntryLeftOfTheClientIsNeverRead(string $entry): void
    {
        $resolver = $this->resolver($this->trustedProxies());

        self::assertSame(self::VISITOR, $resolver->resolve('127.0.0.1', $entry . ', ' . self::VISITOR));
        self::assertSame(
            self::VISITOR,
            $resolver->resolve('127.0.0.1', $entry . ', ' . self::VISITOR . ', ' . self::EDGE)
        );
    }

    /**
     * What a forwarding header can hold besides addresses
     *
     * @return array<string, array{string}>
     */
    public static function unreadableEntries(): array
    {
        return [
            'unknown' => ['unknown'],
            'an address with a port' => [self::VISITOR . ':4711'],
            'a bracketed IPv6 address' => ['[3fff::1]'],
            'an IPv6 address with a zone' => ['fe80::1%eth0'],
            'an empty entry' => [''],
            'garbage' => ['<script>alert(1)</script>'],
        ];
    }

    /**
     * An empty entry between two commas, where the walk has to read, leaves no client
     *
     * @return void
     */
    public function testAnEmptyEntryBetweenCommasLeavesNoClient(): void
    {
        self::assertNull(
            $this->resolver($this->trustedProxies())->resolve('127.0.0.1', self::VISITOR . ', , 10.0.0.5')
        );
    }

    /**
     * When the configured ranges cannot be read, no client is determined
     *
     * @return void
     */
    public function testUnreadableOwnRangesLeaveNoClient(): void
    {
        $trustedProxies = $this->trustedProxies();
        $trustedProxies->ownUndecidable = true;

        self::assertNull($this->resolver($trustedProxies)->resolve(self::VISITOR, ''));
    }

    /**
     * When whether the address after the own proxies is an edge server cannot be decided, no client is determined
     *
     * @return void
     */
    public function testAnUndecidableEdgeLeavesNoClient(): void
    {
        $trustedProxies = $this->trustedProxies();
        $trustedProxies->edgeUndecidable = true;

        self::assertNull($this->resolver($trustedProxies)->resolve('127.0.0.1', self::VISITOR));
        self::assertSame([self::VISITOR], $trustedProxies->edgeAsked);
    }

    /**
     * The walk reads at most twenty addresses, the connecting address and the client included
     *
     * @dataProvider longChains
     * @param int $ownProxies Number of own proxies, the connecting address included
     * @param bool $behindEdge Whether an edge server sits between the own proxies and the client
     * @param string|null $expected The client, or null when it lies beyond what the walk reads
     * @return void
     */
    public function testReadsAtMostTwentyAddresses(int $ownProxies, bool $behindEdge, ?string $expected): void
    {
        $entries = array_fill(0, $ownProxies - 1, '10.0.0.5');
        if ($behindEdge) {
            $entries[] = self::EDGE;
        }
        $entries[] = self::VISITOR;
        $entries[] = '203.0.113.66';

        $client = $this->resolver($this->trustedProxies())->resolve(
            '127.0.0.1',
            implode(', ', array_reverse($entries))
        );

        self::assertSame($expected, $client);
    }

    /**
     * Chains on both sides of the limit
     *
     * @return array<string, array{int, bool, string|null}>
     */
    public static function longChains(): array
    {
        return [
            '19 own proxies, then the client: 20 addresses' => [19, false, self::VISITOR],
            '20 own proxies, then the client: 21 addresses' => [20, false, null],
            '21 own proxies' => [21, false, null],
            '18 own proxies, an edge server, then the client: 20 addresses' => [18, true, self::VISITOR],
            '19 own proxies, an edge server, then the client: 21 addresses' => [19, true, null],
        ];
    }

    /**
     * The same chain asked twice is walked once
     *
     * @return void
     */
    public function testTheSameChainIsWalkedOnce(): void
    {
        $trustedProxies = $this->trustedProxies();
        $resolver = $this->resolver($trustedProxies);
        $chain = self::VISITOR . ', ' . self::EDGE . ', 127.0.0.1';

        $first = $resolver->resolve('127.0.0.1', $chain);
        $second = $resolver->resolve('127.0.0.1', $chain);

        self::assertSame(self::VISITOR, $first);
        self::assertSame(self::VISITOR, $second);
        self::assertSame(['127.0.0.1', '127.0.0.1', self::EDGE], $trustedProxies->ownAsked);
        self::assertSame([self::EDGE], $trustedProxies->edgeAsked);
    }

    /**
     * A different chain is walked again, also after one that left no client
     *
     * @return void
     */
    public function testADifferentChainIsWalkedAgain(): void
    {
        $resolver = $this->resolver($this->trustedProxies());

        self::assertNull($resolver->resolve('127.0.0.1', ''));
        self::assertSame(self::VISITOR, $resolver->resolve('127.0.0.1', self::VISITOR));
        self::assertNull($resolver->resolve('127.0.0.1', ''));
    }

    /**
     * The walk over the real address parser and the given trusted proxies
     *
     * @param FixedTrustedProxyRanges $trustedProxies Which addresses are proxies
     * @return ForwardingChainResolver
     */
    private function resolver(FixedTrustedProxyRanges $trustedProxies): ForwardingChainResolver
    {
        return new ForwardingChainResolver(new AddressParser(), $trustedProxies);
    }

    /**
     * Own proxies in 127.0.0.0/8, 10.0.0.0/8 and ::1/128, edge servers in 192.0.2.0/24
     *
     * @return FixedTrustedProxyRanges
     */
    private function trustedProxies(): FixedTrustedProxyRanges
    {
        $builder = new RangeSnapshotBuilder($this->snapshotFactory());

        return new FixedTrustedProxyRanges(
            $builder->build(['127.0.0.0/8', '10.0.0.0/8', '::1/128'], 1),
            $builder->build(['192.0.2.0/24'], 1)
        );
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
