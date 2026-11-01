<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Config;

use Magento\Config\Model\Config;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The module's admin section as the admin saves it: through the configuration model the admin save runs, never
 * through the declaring file.
 *
 * @magentoAppArea adminhtml
 */
class StructureTest extends TestCase
{
    private const SECTION = 'hryvinskyi_bot_verification';

    /**
     * Saving the section with a line that is not a range is refused with the backend model's message, and nothing is
     * stored
     *
     * @magentoDbIsolation enabled
     * @return void
     */
    public function testTheAdminSaveRefusesALineThatIsNotARange(): void
    {
        $config = Bootstrap::getObjectManager()->create(Config::class, ['data' => [
            'section' => self::SECTION,
            'website' => null,
            'store' => null,
            'groups' => [
                'client_address' => [
                    'fields' => ['trusted_proxies' => ['value' => "10.0.0.0/8\nloopback"]],
                ],
            ],
        ]]);
        self::assertInstanceOf(Config::class, $config);

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('"loopback" is not a CIDR range, e.g. 10.0.0.0/8 or 2001:db8::/32.');
        $config->save();
    }
}
