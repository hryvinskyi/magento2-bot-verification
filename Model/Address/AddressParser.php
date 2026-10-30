<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Address;

use Hryvinskyi\BotVerification\Api\AddressParserInterface;

/**
 * Reads an address into its packed form; the one place where address text becomes bytes
 *
 * A private, loopback or reserved address is what a misconfigured proxy produces - every request then appears to
 * come from the proxy - so it can neither confirm nor accuse a crawler, and the public reading takes it as no
 * address at all. The plain reading keeps it: a proxy in front of the shop is usually at such an address.
 *
 * An IPv6 address that carries an IPv4 address (`::ffff:a.b.c.d`) is read as that IPv4 address: the public/private
 * test applies to the IPv4 address it carries, and the result is compared with IPv4 ranges.
 */
class AddressParser implements AddressParserInterface
{
    /**
     * @inheritDoc
     */
    public function toBinary(string $ip): ?string
    {
        // Packing invalid text raises a warning, which the application's error handler turns into an exception, so
        // the text is validated first.
        $text = trim($ip);
        if (filter_var($text, FILTER_VALIDATE_IP) === false) {
            return null;
        }
        $packed = inet_pton($text);
        if ($packed === false) {
            return null;
        }

        return $this->isIpv4Mapped($packed) ? substr($packed, strlen(self::IPV4_MAPPED_PREFIX)) : $packed;
    }

    /**
     * @inheritDoc
     */
    public function toPublicBinary(string $ip): ?string
    {
        $packed = $this->toBinary($ip);
        if ($packed === null) {
            return null;
        }
        $text = inet_ntop($packed);
        if ($text === false
            || filter_var($text, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false
        ) {
            return null;
        }

        return $packed;
    }

    /**
     * Whether a packed address is an IPv6 address that carries an IPv4 address
     *
     * @param string $packed Packed valid address, 4 or 16 bytes
     * @return bool
     */
    private function isIpv4Mapped(string $packed): bool
    {
        return strlen($packed) === 16 && str_starts_with($packed, self::IPV4_MAPPED_PREFIX);
    }
}
