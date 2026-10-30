<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * Reads a client address written as text into the packed form address ranges are compared with
 *
 * Whitespace around the text is ignored. An IPv6 address that carries an IPv4 address (`::ffff:a.b.c.d`) is read as
 * that IPv4 address, so it is compared with IPv4 ranges.
 */
interface AddressParserInterface
{
    /**
     * The first 12 bytes of an IPv6 address that carries an IPv4 address in its last 4 (`::ffff:a.b.c.d`)
     */
    public const IPV4_MAPPED_PREFIX = "\0\0\0\0\0\0\0\0\0\0\xff\xff";

    /**
     * Packed form of any valid address, public or not
     *
     * @param string $ip Address as text, as a request or a forwarding header reports it
     * @return string|null 4 bytes (IPv4, an IPv4-mapped IPv6 address included) or 16 bytes (IPv6) in network byte
     *                     order; null for anything that is not an address - a port suffix, brackets, a zone index,
     *                     an empty text
     */
    public function toBinary(string $ip): ?string;

    /**
     * Packed form of a valid public address
     *
     * @param string $ip Address as text, as the request reports it
     * @return string|null 4 bytes (IPv4) or 16 bytes (IPv6) in network byte order; null for anything that is not a
     *                     valid public address
     */
    public function toPublicBinary(string $ip): ?string;
}
