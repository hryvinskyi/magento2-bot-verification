<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * The settings an administrator gives the module
 */
interface ConfigInterface
{
    /**
     * The ranges of the shop's own proxies, as configured for the whole installation
     *
     * @return list<string> CIDR ranges in the order they were entered, without blank lines or surrounding whitespace;
     *                      [] when none is configured
     */
    public function getTrustedProxies(): array;

    /**
     * Whether a request that claims a crawler from outside that crawler's published addresses is refused
     *
     * Read before the request is routed, so for the store the request has been resolved to by then, which is not
     * always the store the request goes on to be served in: a storefront request gets the store its host, store code
     * or store cookie resolves to; a REST or GraphQL request gets the store its host resolves to, not the one named
     * in the URL path or the `Store` header; the admin reads the default store view. Off unless an administrator
     * turned it on for that store.
     *
     * @return bool
     */
    public function isImpostorRefusalEnabled(): bool;
}
