<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model;

use Hryvinskyi\BotVerification\Api\ConfigInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The settings as the container builds them and reads them from the merged configuration
 */
class ConfigTest extends TestCase
{
    /**
     * Nothing is refused on a shop that has not turned refusal on
     *
     * @return void
     */
    public function testRefusalShipsOff(): void
    {
        self::assertFalse(self::config()->isImpostorRefusalEnabled());
    }

    /**
     * The switch an administrator turns on for the store is read for that store
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testTheSwitchIsReadForTheCurrentStore(): void
    {
        self::assertTrue(self::config()->isImpostorRefusalEnabled());
    }

    /**
     * The multi-line trusted proxies the module ships are read as one range per line through the container's
     * line reader
     *
     * @return void
     */
    public function testTheShippedTrustedProxiesAreReadOnePerLine(): void
    {
        self::assertSame(
            [
                '127.0.0.0/8',
                '::1/128',
                '10.0.0.0/8',
                '172.16.0.0/12',
                '192.168.0.0/16',
                '100.64.0.0/10',
                'fc00::/7',
            ],
            self::config()->getTrustedProxies()
        );
    }

    /**
     * The module's settings as the container builds them
     *
     * @return ConfigInterface
     */
    private static function config(): ConfigInterface
    {
        $config = Bootstrap::getObjectManager()->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $config);

        return $config;
    }
}
