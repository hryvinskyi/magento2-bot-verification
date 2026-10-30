<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\ConfigInterface;

/**
 * Settings fixed when the double is built, and a count of how often the trusted proxies were read
 */
class FixedConfig implements ConfigInterface
{
    /**
     * Number of times the trusted proxies were read
     */
    public int $trustedProxyReads = 0;

    /**
     * @param list<string> $trustedProxies The configured ranges of the shop's own proxies
     * @param bool $isImpostorRefusalEnabled Whether impostors are refused
     */
    public function __construct(
        private readonly array $trustedProxies = [],
        private readonly bool $isImpostorRefusalEnabled = false
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTrustedProxies(): array
    {
        ++$this->trustedProxyReads;

        return $this->trustedProxies;
    }

    /**
     * @inheritDoc
     */
    public function isImpostorRefusalEnabled(): bool
    {
        return $this->isImpostorRefusalEnabled;
    }
}
