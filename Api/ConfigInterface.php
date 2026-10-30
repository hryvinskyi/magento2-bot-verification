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
     * Read for the store the request is served in; off unless an administrator turned it on.
     *
     * @return bool
     */
    public function isImpostorRefusalEnabled(): bool;
}
