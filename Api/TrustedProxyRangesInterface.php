<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * Whether an address is a server in front of the shop that forwards visitors' requests
 *
 * Two kinds of proxy are told apart, because a forwarding chain may pass them differently: the shop's own proxies,
 * in the ranges an administrator configured, and the edge servers of a content delivery network, in the proxy lists
 * that installed modules declare.
 */
interface TrustedProxyRangesInterface
{
    /**
     * Whether the address is one of the shop's own proxies (in the configured trusted-proxy ranges)
     *
     * @param string $address Packed address (4 or 16 bytes)
     * @return bool|null Null when the stored configuration cannot be read
     */
    public function isOwnProxy(string $address): ?bool;

    /**
     * Whether the address is a CDN edge server (in a proxy list a provider module declares)
     *
     * @param string $address Packed address (4 or 16 bytes)
     * @return bool|null True when a declared list holds it, fresh or stale; false when every declared list is present,
     *                   none holds it and none that holds ranges of its family is stale; null otherwise
     */
    public function isEdgeProxy(string $address): ?bool;
}
