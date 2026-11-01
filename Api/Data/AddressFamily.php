<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api\Data;

/**
 * An address family a published list can hold ranges of
 *
 * A list speaks for each family on its own: one that publishes IPv4 ranges only says nothing about IPv6 addresses,
 * and a list that loses one family has lost it whatever happens to the other.
 *
 * @api
 */
enum AddressFamily: string
{
    case Ipv4 = 'ipv4';
    case Ipv6 = 'ipv6';

    /**
     * Number of bits in an address of the family
     *
     * @return int 32 for IPv4, 128 for IPv6
     */
    public function bits(): int
    {
        return match ($this) {
            self::Ipv4 => 32,
            self::Ipv6 => 128,
        };
    }

    /**
     * The family's name as people write it
     *
     * @return string "IPv4" or "IPv6"
     */
    public function label(): string
    {
        return match ($this) {
            self::Ipv4 => 'IPv4',
            self::Ipv6 => 'IPv6',
        };
    }
}
