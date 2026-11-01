<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Range;

use Hryvinskyi\BotVerification\Api\Data\AddressFamily;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use PHPUnit\Framework\TestCase;

/**
 * A snapshot finds an address in its packed, sorted ranges, knows its age and refuses records of the wrong size.
 *
 * Every snapshot here is built from bytes packed by hand, independently of the snapshot builder.
 */
class RangeSnapshotTest extends TestCase
{
    private const FETCHED_AT = 1_700_000_000;

    /**
     * Records are 4-byte start and 4-byte end in network byte order
     *
     * @return void
     */
    public function testReadsRecordsInNetworkByteOrder(): void
    {
        $snapshot = new RangeSnapshot("\xC6\x33\x64\x00\xC6\x33\x64\xFF", '', self::FETCHED_AT);

        self::assertTrue($snapshot->contains("\xC6\x33\x64\x00"));
        self::assertTrue($snapshot->contains("\xC6\x33\x64\x80"));
        self::assertTrue($snapshot->contains("\xC6\x33\x64\xFF"));
        self::assertFalse($snapshot->contains("\xC6\x33\x63\xFF"));
        self::assertFalse($snapshot->contains("\xC6\x33\x65\x00"));
    }

    /**
     * Each address against two IPv4 ranges: inside at both edges, outside one step beyond, between and around them
     *
     * @dataProvider ipv4Addresses
     * @param string $address Address as text
     * @param bool $expected Whether it lies in one of the ranges
     * @return void
     */
    public function testFindsIpv4Addresses(string $address, bool $expected): void
    {
        $snapshot = new RangeSnapshot(
            self::record('198.51.100.0', '198.51.100.255') . self::record('203.0.113.0', '203.0.113.31'),
            '',
            self::FETCHED_AT
        );

        self::assertSame($expected, $snapshot->contains(self::packed($address)));
    }

    /**
     * Addresses around 198.51.100.0-198.51.100.255 and 203.0.113.0-203.0.113.31
     *
     * @return array<string, array{string, bool}>
     */
    public static function ipv4Addresses(): array
    {
        return [
            'before the first range' => ['192.0.2.1', false],
            'one below the first start' => ['198.51.99.255', false],
            'first address of the first range' => ['198.51.100.0', true],
            'inside the first range' => ['198.51.100.77', true],
            'last address of the first range' => ['198.51.100.255', true],
            'one above the first end' => ['198.51.101.0', false],
            'between the two ranges' => ['200.0.0.1', false],
            'one below the second start' => ['203.0.112.255', false],
            'first address of the second range' => ['203.0.113.0', true],
            'last address of the second range' => ['203.0.113.31', true],
            'one above the last end' => ['203.0.113.32', false],
            'after the last range' => ['233.252.0.1', false],
            'lowest address' => ['0.0.0.0', false],
            'highest address' => ['255.255.255.255', false],
        ];
    }

    /**
     * Each address against two IPv6 ranges, at and around their edges
     *
     * @dataProvider ipv6Addresses
     * @param string $address Address as text
     * @param bool $expected Whether it lies in one of the ranges
     * @return void
     */
    public function testFindsIpv6Addresses(string $address, bool $expected): void
    {
        $snapshot = new RangeSnapshot(
            '',
            self::record('2001:db8:4801::', '2001:db8:4801::ffff:ffff:ffff:ffff')
                . self::record('2001:db8:ff00::', '2001:db8:ff00::ff'),
            self::FETCHED_AT
        );

        self::assertSame($expected, $snapshot->contains(self::packed($address)));
    }

    /**
     * Addresses around 2001:db8:4801::/64 and 2001:db8:ff00::-2001:db8:ff00::ff
     *
     * @return array<string, array{string, bool}>
     */
    public static function ipv6Addresses(): array
    {
        return [
            'before the first range' => ['2001:db8::1', false],
            'one below the first start' => ['2001:db8:4800:ffff:ffff:ffff:ffff:ffff', false],
            'first address of the first range' => ['2001:db8:4801::', true],
            'last address of the first range' => ['2001:db8:4801::ffff:ffff:ffff:ffff', true],
            'one above the first end' => ['2001:db8:4801:1::', false],
            'between the two ranges' => ['2001:db8:9000::1', false],
            'first address of the second range' => ['2001:db8:ff00::', true],
            'last address of the second range' => ['2001:db8:ff00::ff', true],
            'one above the last end' => ['2001:db8:ff00::100', false],
            'after the last range' => ['ffff::1', false],
        ];
    }

    /**
     * A range of a single address holds that address and neither neighbour, in both families
     *
     * @return void
     */
    public function testSingleAddressRanges(): void
    {
        $snapshot = new RangeSnapshot(
            self::record('192.0.2.7', '192.0.2.7'),
            self::record('2001:db8::7', '2001:db8::7'),
            self::FETCHED_AT
        );

        self::assertTrue($snapshot->contains(self::packed('192.0.2.7')));
        self::assertFalse($snapshot->contains(self::packed('192.0.2.6')));
        self::assertFalse($snapshot->contains(self::packed('192.0.2.8')));
        self::assertTrue($snapshot->contains(self::packed('2001:db8::7')));
        self::assertFalse($snapshot->contains(self::packed('2001:db8::6')));
        self::assertFalse($snapshot->contains(self::packed('2001:db8::8')));
    }

    /**
     * The whole IPv4 space holds every IPv4 address, the lowest and the highest included
     *
     * @return void
     */
    public function testWholeIpv4Space(): void
    {
        $snapshot = new RangeSnapshot(self::record('0.0.0.0', '255.255.255.255'), '', self::FETCHED_AT);

        self::assertTrue($snapshot->contains(self::packed('0.0.0.0')));
        self::assertTrue($snapshot->contains(self::packed('192.0.2.1')));
        self::assertTrue($snapshot->contains(self::packed('255.255.255.255')));
        self::assertSame(1, $snapshot->countRanges());
    }

    /**
     * The search finds the edges of every range of a long list, and nothing in the gaps between them
     *
     * @return void
     */
    public function testFindsEveryRangeOfALongList(): void
    {
        $ranges = '';
        for ($third = 0; $third < 200; $third++) {
            $ranges .= pack('C4', 198, 18, $third, 0) . pack('C4', 198, 18, $third, 127);
        }
        $snapshot = new RangeSnapshot($ranges, '', self::FETCHED_AT);

        self::assertSame(200, $snapshot->countRanges());
        for ($third = 0; $third < 200; $third++) {
            self::assertTrue($snapshot->contains(pack('C4', 198, 18, $third, 0)), "198.18.$third.0");
            self::assertTrue($snapshot->contains(pack('C4', 198, 18, $third, 127)), "198.18.$third.127");
            self::assertFalse($snapshot->contains(pack('C4', 198, 18, $third, 128)), "198.18.$third.128");
            self::assertFalse($snapshot->contains(pack('C4', 198, 18, $third, 255)), "198.18.$third.255");
        }
        self::assertFalse($snapshot->contains(pack('C4', 198, 17, 255, 255)));
        self::assertFalse($snapshot->contains(pack('C4', 198, 18, 200, 0)));
    }

    /**
     * A snapshot without ranges holds no address of either family
     *
     * @return void
     */
    public function testEmptyFamiliesHoldNothing(): void
    {
        $snapshot = new RangeSnapshot('', '', self::FETCHED_AT);

        self::assertFalse($snapshot->contains(self::packed('192.0.2.1')));
        self::assertFalse($snapshot->contains(self::packed('2001:db8::1')));
        self::assertSame(0, $snapshot->countRanges());
    }

    /**
     * An address of any length other than 4 or 16 bytes lies in no range, even of a list covering everything
     *
     * @dataProvider wrongLengths
     * @param string $address Bytes that are not a packed address
     * @return void
     */
    public function testAddressOfAnotherLengthIsInNoRange(string $address): void
    {
        $snapshot = new RangeSnapshot(
            self::record('0.0.0.0', '255.255.255.255'),
            self::record('::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'),
            self::FETCHED_AT
        );

        self::assertFalse($snapshot->contains($address));
    }

    /**
     * Byte strings that are neither 4 nor 16 bytes long
     *
     * @return array<string, array{string}>
     */
    public static function wrongLengths(): array
    {
        return [
            'empty' => [''],
            '1 byte' => ["\x01"],
            '3 bytes' => ["\xC0\x00\x02"],
            '5 bytes' => ["\xC0\x00\x02\x01\x00"],
            '8 bytes' => [str_repeat("\x00", 8)],
            '15 bytes' => [str_repeat("\x00", 15)],
            '17 bytes' => [str_repeat("\x00", 17)],
            '32 bytes' => [str_repeat("\x00", 32)],
        ];
    }

    /**
     * An IPv4 address is never looked up in IPv6 ranges, nor an IPv6 address in IPv4 ranges
     *
     * @return void
     */
    public function testFamiliesAreSeparate(): void
    {
        $ipv4Only = new RangeSnapshot(self::record('0.0.0.0', '255.255.255.255'), '', self::FETCHED_AT);
        $ipv6Only = new RangeSnapshot(
            '',
            self::record('::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'),
            self::FETCHED_AT
        );

        self::assertFalse($ipv4Only->contains(self::packed('2001:db8::1')));
        self::assertFalse($ipv6Only->contains(self::packed('192.0.2.1')));
        self::assertTrue($ipv4Only->contains(self::packed('192.0.2.1')));
        self::assertTrue($ipv6Only->contains(self::packed('2001:db8::1')));
    }

    /**
     * A snapshot holds the family of an address exactly when it has at least one range of that family, whether or not
     * the address lies in one
     *
     * @return void
     */
    public function testHoldsTheFamilyOfAnAddressItHasRangesOf(): void
    {
        $ipv4Only = new RangeSnapshot(self::record('198.51.100.0', '198.51.100.255'), '', self::FETCHED_AT);
        $ipv6Only = new RangeSnapshot('', self::record('2001:db8::', '2001:db8::ff'), self::FETCHED_AT);

        self::assertTrue($ipv4Only->holdsFamilyOf(self::packed('203.0.113.9')));
        self::assertFalse($ipv4Only->holdsFamilyOf(self::packed('3fff::7')));
        self::assertTrue($ipv6Only->holdsFamilyOf(self::packed('3fff::7')));
        self::assertFalse($ipv6Only->holdsFamilyOf(self::packed('203.0.113.9')));
    }

    /**
     * A snapshot with no range at all holds neither family
     *
     * @return void
     */
    public function testAnEmptySnapshotHoldsNoFamily(): void
    {
        $snapshot = new RangeSnapshot('', '', self::FETCHED_AT);

        self::assertFalse($snapshot->holdsFamilyOf(self::packed('192.0.2.1')));
        self::assertFalse($snapshot->holdsFamilyOf(self::packed('2001:db8::1')));
    }

    /**
     * An address of any length other than 4 or 16 bytes belongs to no family, even of a list of both
     *
     * @dataProvider wrongLengths
     * @param string $address Bytes that are not a packed address
     * @return void
     */
    public function testAddressOfAnotherLengthBelongsToNoFamily(string $address): void
    {
        $snapshot = new RangeSnapshot(
            self::record('0.0.0.0', '255.255.255.255'),
            self::record('::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'),
            self::FETCHED_AT
        );

        self::assertFalse($snapshot->holdsFamilyOf($address));
    }

    /**
     * A snapshot exactly as old as the limit is not older; one second more is
     *
     * @return void
     */
    public function testAgeBoundary(): void
    {
        $snapshot = new RangeSnapshot('', '', 1000);

        self::assertFalse($snapshot->isOlderThan(60, 1000));
        self::assertFalse($snapshot->isOlderThan(60, 1059));
        self::assertFalse($snapshot->isOlderThan(60, 1060));
        self::assertTrue($snapshot->isOlderThan(60, 1061));
        self::assertFalse($snapshot->isOlderThan(60, 500));
        self::assertTrue($snapshot->isOlderThan(0, 1001));
        self::assertFalse($snapshot->isOlderThan(0, 1000));
    }

    /**
     * The records and the fetch moment come back exactly as given, and both families are counted
     *
     * @return void
     */
    public function testKeepsWhatItIsGiven(): void
    {
        $ipv4 = self::record('192.0.2.0', '192.0.2.255') . self::record('198.51.100.0', '198.51.100.255');
        $ipv6 = self::record('2001:db8::', '2001:db8::ffff');
        $snapshot = new RangeSnapshot($ipv4, $ipv6, self::FETCHED_AT);

        self::assertSame($ipv4, $snapshot->getIpv4Ranges());
        self::assertSame($ipv6, $snapshot->getIpv6Ranges());
        self::assertSame(self::FETCHED_AT, $snapshot->getFetchedAt());
        self::assertSame(3, $snapshot->countRanges());
    }

    /**
     * Families that are not whole records, and a fetch moment that is not positive, are refused
     *
     * @dataProvider refusedArguments
     * @param string $ipv4Ranges IPv4 records to build with
     * @param string $ipv6Ranges IPv6 records to build with
     * @param int $fetchedAt Fetch moment to build with
     * @return void
     */
    public function testRefusesInvalidArguments(string $ipv4Ranges, string $ipv6Ranges, int $fetchedAt): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new RangeSnapshot($ipv4Ranges, $ipv6Ranges, $fetchedAt);
    }

    /**
     * Arguments a snapshot cannot be built from
     *
     * @return array<string, array{string, string, int}>
     */
    public static function refusedArguments(): array
    {
        return [
            'IPv4 one byte short of a record' => [str_repeat("\x00", 7), '', self::FETCHED_AT],
            'IPv4 one byte over a record' => [str_repeat("\x00", 9), '', self::FETCHED_AT],
            'IPv4 given an IPv6-sized half record' => [str_repeat("\x00", 4), '', self::FETCHED_AT],
            'IPv6 one byte short of a record' => ['', str_repeat("\x00", 31), self::FETCHED_AT],
            'IPv6 one byte over a record' => ['', str_repeat("\x00", 33), self::FETCHED_AT],
            'IPv6 given an IPv4 record' => ['', str_repeat("\x00", 8), self::FETCHED_AT],
            'fetched at zero' => ['', '', 0],
            'fetched before the epoch' => ['', '', -1],
        ];
    }

    /**
     * IPv4 coverage is the exact number of addresses the records span, summed over the records; the IPv6 family of the
     * same snapshot covers nothing
     *
     * @return void
     */
    public function testCountsTheIpv4AddressesCovered(): void
    {
        $snapshot = new RangeSnapshot(
            self::record('192.0.2.7', '192.0.2.7')
                . self::record('198.51.100.0', '198.51.100.255')
                . self::record('203.0.113.0', '203.0.113.31'),
            '',
            self::FETCHED_AT
        );

        self::assertSame(289.0, $snapshot->coveredAddresses(AddressFamily::Ipv4));
        self::assertSame(0.0, $snapshot->coveredAddresses(AddressFamily::Ipv6));
    }

    /**
     * A range whose last address carries into the next byte spans its true width: the difference borrows across the
     * byte boundary
     *
     * @return void
     */
    public function testARangeAcrossAByteBoundarySpansItsTrueWidth(): void
    {
        $snapshot = new RangeSnapshot(self::record('198.51.100.255', '198.51.101.0'), '', self::FETCHED_AT);

        self::assertSame(2.0, $snapshot->coveredAddresses(AddressFamily::Ipv4));
    }

    /**
     * Touching records cover what the one record they merge into covers
     *
     * @return void
     */
    public function testTouchingRecordsCoverWhatTheirMergedRecordCovers(): void
    {
        $touching = new RangeSnapshot(
            self::record('192.0.2.0', '192.0.2.127') . self::record('192.0.2.128', '192.0.2.255'),
            self::record('2001:db8::', '2001:db8::ff') . self::record('2001:db8::100', '2001:db8::1ff'),
            self::FETCHED_AT
        );
        $merged = new RangeSnapshot(
            self::record('192.0.2.0', '192.0.2.255'),
            self::record('2001:db8::', '2001:db8::1ff'),
            self::FETCHED_AT
        );

        self::assertSame(256.0, $touching->coveredAddresses(AddressFamily::Ipv4));
        self::assertSame(512.0, $touching->coveredAddresses(AddressFamily::Ipv6));
        foreach (AddressFamily::cases() as $family) {
            self::assertSame($merged->coveredAddresses($family), $touching->coveredAddresses($family));
        }
    }

    /**
     * IPv6 coverage of prefixes is the power of two they are worth, and a small range high in the space keeps its
     * exact width: the span is not the difference of two rounded addresses
     *
     * @dataProvider ipv6Coverage
     * @param string $ipv6Ranges Packed IPv6 records
     * @param float $expected Addresses covered
     * @return void
     */
    public function testMeasuresTheIpv6AddressesCovered(string $ipv6Ranges, float $expected): void
    {
        $snapshot = new RangeSnapshot('', $ipv6Ranges, self::FETCHED_AT);

        self::assertSame($expected, $snapshot->coveredAddresses(AddressFamily::Ipv6));
        self::assertSame(0.0, $snapshot->coveredAddresses(AddressFamily::Ipv4));
    }

    /**
     * IPv6 records and the number of addresses they cover
     *
     * @return array<string, array{string, float}>
     */
    public static function ipv6Coverage(): array
    {
        return [
            'a single address' => [self::record('2001:db8::7', '2001:db8::7'), 1.0],
            'a /32' => [self::record('2001:db8::', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff'), 2.0 ** 96],
            'a /32 and a /48' => [
                self::record('2001:db8::', '2001:db8:ffff:ffff:ffff:ffff:ffff:ffff')
                    . self::record('2001:db9::', '2001:db9:0:ffff:ffff:ffff:ffff:ffff'),
                2.0 ** 96 + 2.0 ** 80,
            ],
            '256 addresses at the top of the space' => [
                self::record('ffff:ffff:ffff:ffff:ffff:ffff:ffff:ff00', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'),
                256.0,
            ],
            'the whole space' => [self::record('::', 'ffff:ffff:ffff:ffff:ffff:ffff:ffff:ffff'), 2.0 ** 128],
        ];
    }

    /**
     * The whole IPv4 space covers 2^32 addresses, exactly
     *
     * @return void
     */
    public function testTheWholeIpv4SpaceCoversEveryAddress(): void
    {
        $snapshot = new RangeSnapshot(self::record('0.0.0.0', '255.255.255.255'), '', self::FETCHED_AT);

        self::assertSame(4294967296.0, $snapshot->coveredAddresses(AddressFamily::Ipv4));
    }

    /**
     * An empty snapshot covers nothing in either family
     *
     * @return void
     */
    public function testAnEmptySnapshotCoversNothing(): void
    {
        $snapshot = new RangeSnapshot('', '', self::FETCHED_AT);

        self::assertSame(0.0, $snapshot->coveredAddresses(AddressFamily::Ipv4));
        self::assertSame(0.0, $snapshot->coveredAddresses(AddressFamily::Ipv6));
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
