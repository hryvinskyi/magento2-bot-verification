<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Address;

use Hryvinskyi\BotVerification\Model\Address\AddressParser;
use PHPUnit\Framework\TestCase;

/**
 * Any valid address is read into its packed form by the plain reading, and only a valid public one by the public
 * reading; an IPv4 address carried in IPv6 is read as IPv4 by both.
 *
 * The public addresses here come from documentation ranges that the address filter treats as public: 203.0.113.0/24
 * for IPv4 and 3fff::/20 for IPv6 (2001:db8::/32 is reserved to the filter, so it stands in for a reserved address).
 */
class AddressParserTest extends TestCase
{
    /**
     * A public address is read into its packed form of 4 or 16 bytes
     *
     * @dataProvider publicAddresses
     * @param string $ip Address as the request reports it
     * @param string $expected The same address as text, packed by the test
     * @param int $length Expected length of the packed form
     * @return void
     */
    public function testReadsAPublicAddress(string $ip, string $expected, int $length): void
    {
        $packed = (new AddressParser())->toPublicBinary($ip);

        self::assertNotNull($packed);
        self::assertSame($length, strlen($packed));
        self::assertSame(inet_pton($expected), $packed);
    }

    /**
     * Public addresses, their plain text and the length of their packed form
     *
     * @return array<string, array{string, string, int}>
     */
    public static function publicAddresses(): array
    {
        return [
            'public IPv4' => ['203.0.113.7', '203.0.113.7', 4],
            'public IPv4 with surrounding whitespace' => [" 203.0.113.7\n", '203.0.113.7', 4],
            'public IPv6' => ['3fff::1', '3fff::1', 16],
            'public IPv6 written in full' => ['3fff:0000:0000:0000:0000:0000:0000:0001', '3fff::1', 16],
            'IPv4-mapped IPv6 is read as IPv4' => ['::ffff:203.0.113.7', '203.0.113.7', 4],
            'IPv4-mapped IPv6 in capitals' => ['::FFFF:203.0.113.7', '203.0.113.7', 4],
            'IPv4-mapped IPv6 written in hex' => ['::ffff:cb00:7107', '203.0.113.7', 4],
        ];
    }

    /**
     * Anything that is not a valid public address is read as no address
     *
     * @dataProvider notPublicAddresses
     * @param string $ip Address as the request reports it
     * @return void
     */
    public function testReadsAnythingElseAsNoAddress(string $ip): void
    {
        self::assertNull((new AddressParser())->toPublicBinary($ip));
    }

    /**
     * Private, loopback, link-local, reserved and unreadable addresses
     *
     * @return array<string, array{string}>
     */
    public static function notPublicAddresses(): array
    {
        return [
            'private 10/8' => ['10.0.0.1'],
            'private 172.16/12' => ['172.16.0.1'],
            'private 192.168/16' => ['192.168.1.1'],
            'loopback IPv4' => ['127.0.0.1'],
            'loopback IPv6' => ['::1'],
            'link-local IPv4' => ['169.254.1.1'],
            'link-local IPv6' => ['fe80::1'],
            'unique local IPv6' => ['fc00::1'],
            'unspecified IPv4' => ['0.0.0.0'],
            'unspecified IPv6' => ['::'],
            'reserved IPv4' => ['240.0.0.1'],
            'reserved IPv6 documentation range' => ['2001:db8::1'],
            'IPv4-mapped private' => ['::ffff:10.0.0.1'],
            'IPv4-mapped loopback' => ['::ffff:127.0.0.1'],
            'IPv4-mapped unspecified' => ['::ffff:0.0.0.0'],
            'empty' => [''],
            'whitespace only' => ['   '],
            'garbage' => ['garbage'],
            'a list of addresses' => ['203.0.113.7, 203.0.113.8'],
            'an address with a port' => ['203.0.113.7:8080'],
            'a prefix' => ['203.0.113.0/24'],
            'out of range' => ['203.0.113.256'],
            'IPv6 with a zone' => ['fe80::1%eth0'],
        ];
    }

    /**
     * The plain reading packs any valid address, public or not, an IPv4-mapped one as IPv4
     *
     * @dataProvider validAddresses
     * @param string $ip Address as text
     * @param string $expected The address the packed form must hold, as text
     * @param int $length Expected length of the packed form
     * @return void
     */
    public function testPacksAnyValidAddress(string $ip, string $expected, int $length): void
    {
        $packed = (new AddressParser())->toBinary($ip);

        self::assertNotNull($packed);
        self::assertSame($length, strlen($packed));
        self::assertSame(inet_pton($expected), $packed);
    }

    /**
     * Valid addresses of every kind, their plain text and the length of their packed form
     *
     * @return array<string, array{string, string, int}>
     */
    public static function validAddresses(): array
    {
        return [
            'public IPv4' => ['203.0.113.7', '203.0.113.7', 4],
            'loopback IPv4' => ['127.0.0.1', '127.0.0.1', 4],
            'private IPv4 with surrounding whitespace' => [" 10.0.0.5\t", '10.0.0.5', 4],
            'loopback IPv6' => ['::1', '::1', 16],
            'unique local IPv6' => ['fd00::5', 'fd00::5', 16],
            'public IPv6' => ['3fff::7', '3fff::7', 16],
            'IPv4-mapped loopback is read as IPv4' => ['::ffff:127.0.0.1', '127.0.0.1', 4],
            'IPv4-mapped public is read as IPv4' => ['::ffff:203.0.113.7', '203.0.113.7', 4],
        ];
    }

    /**
     * Text that is not an address is not packed
     *
     * @dataProvider notAddresses
     * @param string $ip Text that is not an address
     * @return void
     */
    public function testDoesNotPackAnythingElse(string $ip): void
    {
        self::assertNull((new AddressParser())->toBinary($ip));
    }

    /**
     * What a forwarding header can hold besides addresses
     *
     * @return array<string, array{string}>
     */
    public static function notAddresses(): array
    {
        return [
            'empty' => [''],
            'unknown' => ['unknown'],
            'IPv4 with a port' => ['1.2.3.4:80'],
            'bracketed IPv6' => ['[3fff::1]'],
            'IPv6 with a zone' => ['fe80::1%eth0'],
            'a prefix' => ['10.0.0.0/8'],
            'out of range' => ['203.0.113.256'],
            'markup' => ['<script>alert(1)</script>'],
        ];
    }
}
