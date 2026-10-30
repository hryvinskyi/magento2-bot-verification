<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Range;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use PHPUnit\Framework\TestCase;

/**
 * The builder turns CIDR prefixes into sorted, merged records per family, and rejects a list with any malformed
 * prefix as a whole.
 */
class RangeSnapshotBuilderTest extends TestCase
{
    private const FETCHED_AT = 1_700_000_000;

    /**
     * Host bits set in a prefix are cleared: the range runs from the network address to the last address
     *
     * @dataProvider prefixesWithHostBits
     * @param string $prefix Prefix as published
     * @param string $start Expected first address
     * @param string $end Expected last address
     * @return void
     */
    public function testNormalisesHostBits(string $prefix, string $start, string $end): void
    {
        $snapshot = $this->build([$prefix]);

        $record = self::record($start, $end);
        $isIpv4 = strlen($record) === 8;
        self::assertSame($isIpv4 ? $record : '', $snapshot->getIpv4Ranges());
        self::assertSame($isIpv4 ? '' : $record, $snapshot->getIpv6Ranges());
        self::assertSame(1, $snapshot->countRanges());
    }

    /**
     * Prefixes, some with host bits set, and the range each covers
     *
     * @return array<string, array{string, string, string}>
     */
    public static function prefixesWithHostBits(): array
    {
        return [
            'IPv4 /24 with host bits' => ['192.0.2.77/24', '192.0.2.0', '192.0.2.255'],
            'IPv4 /27 not on a byte boundary' => ['198.51.100.77/27', '198.51.100.64', '198.51.100.95'],
            'IPv4 /20 across a byte boundary' => ['198.51.100.7/20', '198.51.96.0', '198.51.111.255'],
            'IPv4 /32 is one address' => ['203.0.113.9/32', '203.0.113.9', '203.0.113.9'],
            'IPv4 /0 is the whole space' => ['192.0.2.1/0', '0.0.0.0', '255.255.255.255'],
            'IPv6 /64 with host bits' => [
                '2001:db8:4801::1234/64',
                '2001:db8:4801::',
                '2001:db8:4801::ffff:ffff:ffff:ffff',
            ],
            'IPv6 /57 not on a byte boundary' => [
                '2001:db8:4801:ff::/57',
                '2001:db8:4801:80::',
                '2001:db8:4801:ff:ffff:ffff:ffff:ffff',
            ],
            'IPv6 /128 is one address' => ['2001:db8::7/128', '2001:db8::7', '2001:db8::7'],
            'IPv6 /0 is the whole space' => ['2001:db8::1/0', '::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'],
        ];
    }

    /**
     * Prefixes given in any order come out sorted by their first address
     *
     * @return void
     */
    public function testSortsUnsortedPrefixes(): void
    {
        $snapshot = $this->build(['203.0.113.0/27', '192.0.2.0/24', '198.51.100.0/24']);

        self::assertSame(
            self::record('192.0.2.0', '192.0.2.255')
            . self::record('198.51.100.0', '198.51.100.255')
            . self::record('203.0.113.0', '203.0.113.31'),
            $snapshot->getIpv4Ranges()
        );
        self::assertSame(3, $snapshot->countRanges());
    }

    /**
     * A prefix inside another, given before or after it, is merged into it
     *
     * @return void
     */
    public function testMergesNestedPrefixes(): void
    {
        $snapshot = $this->build(['198.51.101.0/24', '198.51.100.0/23', '198.51.100.128/25']);

        self::assertSame(self::record('198.51.100.0', '198.51.101.255'), $snapshot->getIpv4Ranges());
        self::assertSame(1, $snapshot->countRanges());
    }

    /**
     * Two ranges with the same first address merge to the larger, whichever comes first
     *
     * @return void
     */
    public function testMergedRangeKeepsTheLaterEnd(): void
    {
        $narrowFirst = $this->build(['198.51.100.0/24', '198.51.100.0/23']);
        $wideFirst = $this->build(['198.51.100.0/23', '198.51.100.0/24']);

        self::assertSame(self::record('198.51.100.0', '198.51.101.255'), $narrowFirst->getIpv4Ranges());
        self::assertSame(self::record('198.51.100.0', '198.51.101.255'), $wideFirst->getIpv4Ranges());
    }

    /**
     * The same prefix listed twice is one range
     *
     * @return void
     */
    public function testMergesDuplicatePrefixes(): void
    {
        $snapshot = $this->build(['2001:db8:4801::/64', '192.0.2.0/24', '2001:db8:4801::/64', '192.0.2.0/24']);

        self::assertSame(self::record('192.0.2.0', '192.0.2.255'), $snapshot->getIpv4Ranges());
        self::assertSame(
            self::record('2001:db8:4801::', '2001:db8:4801::ffff:ffff:ffff:ffff'),
            $snapshot->getIpv6Ranges()
        );
        self::assertSame(2, $snapshot->countRanges());
    }

    /**
     * Two ranges that touch - one starts at the address right after the other ends - are one block, in either order
     *
     * @return void
     */
    public function testMergesTouchingRanges(): void
    {
        $snapshot = $this->build(['198.51.100.128/25', '198.51.100.0/25']);

        self::assertSame(self::record('198.51.100.0', '198.51.100.255'), $snapshot->getIpv4Ranges());
        self::assertSame(1, $snapshot->countRanges());
        self::assertTrue($snapshot->contains(self::packed('198.51.100.127')));
        self::assertTrue($snapshot->contains(self::packed('198.51.100.128')));
    }

    /**
     * The address after a range's end is found with a carry across bytes, in both families
     *
     * @return void
     */
    public function testMergesTouchingRangesAcrossAByteBoundary(): void
    {
        $snapshot = $this->build(['198.51.101.0/24', '198.51.100.0/24', '2001:db8:1::/48', '2001:db8::/48']);

        self::assertSame(self::record('198.51.100.0', '198.51.101.255'), $snapshot->getIpv4Ranges());
        self::assertSame(
            self::record('2001:db8::', '2001:db8:1:ffff:ffff:ffff:ffff:ffff'),
            $snapshot->getIpv6Ranges()
        );
        self::assertSame(2, $snapshot->countRanges());
    }

    /**
     * Ranges one address apart are two blocks, and the address between them is in neither
     *
     * @return void
     */
    public function testKeepsRangesWithAGapApart(): void
    {
        $snapshot = $this->build(['198.51.100.0/25', '198.51.100.129/32']);

        self::assertSame(
            self::record('198.51.100.0', '198.51.100.127') . self::record('198.51.100.129', '198.51.100.129'),
            $snapshot->getIpv4Ranges()
        );
        self::assertSame(2, $snapshot->countRanges());
        self::assertFalse($snapshot->contains(self::packed('198.51.100.128')));
    }

    /**
     * A range that ends at the last address of the space has no address after it, and merges what overlaps it
     *
     * @return void
     */
    public function testARangeEndingAtTheTopOfTheSpace(): void
    {
        $snapshot = $this->build([
            '255.255.255.0/24',
            '255.255.255.255/32',
            '255.255.254.0/24',
            'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ff00/120',
        ]);

        self::assertSame(self::record('255.255.254.0', '255.255.255.255'), $snapshot->getIpv4Ranges());
        self::assertSame(
            self::record('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ff00', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'),
            $snapshot->getIpv6Ranges()
        );
        self::assertTrue($snapshot->contains(self::packed('255.255.255.255')));
    }

    /**
     * An IPv6 prefix inside the space of IPv6 addresses that carry an IPv4 address is an IPv4 range
     *
     * @return void
     */
    public function testReadsAnIpv4MappedPrefixAsIpv4(): void
    {
        $snapshot = $this->build(['::ffff:192.0.2.0/120']);

        self::assertSame(self::record('192.0.2.0', '192.0.2.255'), $snapshot->getIpv4Ranges());
        self::assertSame('', $snapshot->getIpv6Ranges());
        self::assertTrue($snapshot->contains(self::packed('192.0.2.77')));
    }

    /**
     * An IPv4-mapped single address is a single IPv4 address
     *
     * @return void
     */
    public function testReadsAnIpv4MappedAddressAsOneIpv4Address(): void
    {
        $snapshot = $this->build(['::ffff:192.0.2.1/128']);

        self::assertSame(self::record('192.0.2.1', '192.0.2.1'), $snapshot->getIpv4Ranges());
        self::assertSame('', $snapshot->getIpv6Ranges());
    }

    /**
     * A prefix shorter than the mapped space reaches beyond it, so it stays an IPv6 range
     *
     * @return void
     */
    public function testKeepsAPrefixWiderThanTheMappedSpaceInIpv6(): void
    {
        $snapshot = $this->build(['::ffff:0:0/95']);

        self::assertSame('', $snapshot->getIpv4Ranges());
        self::assertSame(self::record('::fffe:0:0', '::ffff:ffff:ffff'), $snapshot->getIpv6Ranges());
    }

    /**
     * IPv4 and IPv6 prefixes given together are separated into their families
     *
     * @return void
     */
    public function testSeparatesFamilies(): void
    {
        $snapshot = $this->build(['2001:db8:ff00::/120', '192.0.2.0/24', '2001:db8:4801::/64']);

        self::assertSame(self::record('192.0.2.0', '192.0.2.255'), $snapshot->getIpv4Ranges());
        self::assertSame(
            self::record('2001:db8:4801::', '2001:db8:4801::ffff:ffff:ffff:ffff')
            . self::record('2001:db8:ff00::', '2001:db8:ff00::ff'),
            $snapshot->getIpv6Ranges()
        );
        self::assertSame(3, $snapshot->countRanges());
    }

    /**
     * A list of one family leaves the other empty, and the fetch moment is passed on
     *
     * @return void
     */
    public function testListOfOneFamily(): void
    {
        $ipv4Only = $this->build(['192.0.2.0/24']);
        $ipv6Only = $this->build(['2001:db8::/32']);

        self::assertSame('', $ipv4Only->getIpv6Ranges());
        self::assertSame('', $ipv6Only->getIpv4Ranges());
        self::assertSame(self::FETCHED_AT, $ipv4Only->getFetchedAt());
        self::assertSame(self::FETCHED_AT, $ipv6Only->getFetchedAt());
    }

    /**
     * A malformed prefix rejects the list and names the prefix
     *
     * @dataProvider malformedPrefixes
     * @param string $prefix Prefix that is not a valid CIDR prefix
     * @return void
     */
    public function testRejectsAMalformedPrefix(string $prefix): void
    {
        try {
            $this->build([$prefix]);
            self::fail('The prefix "' . $prefix . '" was accepted.');
        } catch (RangeListRejectedException $exception) {
            self::assertSame(
                'The address range "' . $prefix . '" is not a valid CIDR prefix.',
                $exception->getMessage()
            );
        }
    }

    /**
     * Strings that are not CIDR prefixes
     *
     * @return array<string, array{string}>
     */
    public static function malformedPrefixes(): array
    {
        return [
            'no length' => ['1.2.3.4'],
            'IPv4 length beyond 32' => ['1.2.3.4/33'],
            'IPv6 length beyond 128' => ['::/129'],
            'length not a number' => ['1.2.3.4/x'],
            'two slashes' => ['a/b/c'],
            'address out of range' => ['999.1.1.1/8'],
            'empty string' => [''],
            'empty length' => ['1.2.3.4/'],
            'empty address' => ['/24'],
            'trailing slash' => ['192.0.2.0/24/'],
            'signed length' => ['192.0.2.0/+8'],
            'negative length' => ['192.0.2.0/-1'],
            'space before the address' => [' 192.0.2.0/24'],
            'space after the length' => ['192.0.2.0/24 '],
            'IPv6 with a zone' => ['fe80::1%eth0/64'],
            'leading zero in an IPv4 octet' => ['192.0.02.0/24'],
        ];
    }

    /**
     * One malformed prefix among valid ones rejects the whole list
     *
     * @return void
     */
    public function testOneMalformedPrefixRejectsTheWholeList(): void
    {
        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage('The address range "198.51.100.0/33" is not a valid CIDR prefix.');

        $this->build(['192.0.2.0/24', '198.51.100.0/33', '203.0.113.0/27']);
    }

    /**
     * An empty list is rejected
     *
     * @return void
     */
    public function testRejectsAnEmptyList(): void
    {
        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage('The published address list holds no prefixes.');

        $this->build([]);
    }

    /**
     * A dozen prefixes shaped like a published list: each address is found in its own range and nowhere else
     *
     * @return void
     */
    public function testListShapedLikeAPublishedOne(): void
    {
        $snapshot = $this->build([
            '2001:db8:4801:2::/64',
            '198.18.0.0/19',
            '203.0.113.0/27',
            '198.18.32.0/20',
            '2001:db8:4801::/64',
            '198.18.64.0/21',
            '198.51.100.0/24',
            '198.18.96.0/22',
            '2001:db8:4802:10::/64',
            '203.0.113.64/26',
            '198.19.0.0/23',
            '2001:db8:4801:1::/64',
        ]);

        // 198.18.0.0/19 and 198.18.32.0/20 touch, and so do the three 2001:db8:4801:0-2::/64 prefixes.
        self::assertSame(9, $snapshot->countRanges());
        foreach ($this->addressesInThePublishedShape() as $address => $expected) {
            self::assertSame($expected, $snapshot->contains(self::packed($address)), $address);
        }
    }

    /**
     * Addresses at the edges of and between the ranges of the published-shape list
     *
     * @return array<string, bool>
     */
    private function addressesInThePublishedShape(): array
    {
        return [
            '198.18.0.0' => true,
            '198.18.31.255' => true,
            '198.18.32.0' => true,
            '198.18.47.255' => true,
            '198.18.48.0' => false,
            '198.18.64.0' => true,
            '198.18.71.255' => true,
            '198.18.72.0' => false,
            '198.18.99.255' => true,
            '198.18.100.0' => false,
            '198.19.1.255' => true,
            '198.19.2.0' => false,
            '198.51.100.128' => true,
            '203.0.113.31' => true,
            '203.0.113.32' => false,
            '203.0.113.63' => false,
            '203.0.113.64' => true,
            '203.0.113.127' => true,
            '203.0.113.128' => false,
            '192.0.2.1' => false,
            '2001:db8:4801::1' => true,
            '2001:db8:4801:1:ffff:ffff:ffff:ffff' => true,
            '2001:db8:4801:2::abcd' => true,
            '2001:db8:4801:3::' => false,
            '2001:db8:4802:10::1' => true,
            '2001:db8:4802:11::' => false,
            '2001:db8:4800:ffff:ffff:ffff:ffff:ffff' => false,
        ];
    }

    /**
     * Snapshot of the given prefixes, through a factory that builds a real snapshot from its arguments
     *
     * @param list<string> $prefixes Prefixes to build from
     * @return RangeSnapshotInterface
     * @throws RangeListRejectedException When the builder rejects the list
     */
    private function build(array $prefixes): RangeSnapshotInterface
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

        return (new RangeSnapshotBuilder($factory))->build($prefixes, self::FETCHED_AT);
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
