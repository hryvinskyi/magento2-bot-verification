<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Plugin;

use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\Interception\DefinitionInterface;
use Magento\Framework\Interception\PluginListInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The refusal is the outermost plugin of the front controller in every area that dispatches requests
 *
 * The container's plugin list, merged from every enabled module's declarations, is asked for the first step of the
 * dispatch chain of the area's own front controller: no other plugin runs before it, and the first plugin that wraps
 * dispatch is the refusal. Every plugin declared without an order is given order 0, so the refusal's negative order
 * puts it ahead of all of them.
 */
class RefuseImpostorPluginOrderTest extends TestCase
{
    private const PLUGIN = 'hryvinskyi_bot_verification_refuse_impostor';

    /**
     * Storefront requests
     *
     * @magentoAppArea frontend
     * @return void
     */
    public function testOutermostOnTheStorefront(): void
    {
        self::assertTheRefusalIsOutermost();
    }

    /**
     * Admin requests
     *
     * @magentoAppArea adminhtml
     * @return void
     */
    public function testOutermostInTheAdmin(): void
    {
        self::assertTheRefusalIsOutermost();
    }

    /**
     * REST requests
     *
     * @magentoAppArea webapi_rest
     * @return void
     */
    public function testOutermostForRest(): void
    {
        self::assertTheRefusalIsOutermost();
    }

    /**
     * SOAP requests
     *
     * @magentoAppArea webapi_soap
     * @return void
     */
    public function testOutermostForSoap(): void
    {
        self::assertTheRefusalIsOutermost();
    }

    /**
     * GraphQL requests
     *
     * @magentoAppArea graphql
     * @return void
     */
    public function testOutermostForGraphQl(): void
    {
        self::assertTheRefusalIsOutermost();
    }

    /**
     * The first step of the current area's front-controller dispatch is the refusal alone
     *
     * @return void
     */
    private static function assertTheRefusalIsOutermost(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $diConfig = $objectManager->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $diConfig);
        $frontController = $diConfig->getPreference(FrontControllerInterface::class);
        self::assertNotSame(
            FrontControllerInterface::class,
            $frontController,
            'The area has no front controller.'
        );
        $pluginList = $objectManager->get(PluginListInterface::class);
        self::assertInstanceOf(PluginListInterface::class, $pluginList);

        self::assertSame(
            [DefinitionInterface::LISTENER_AROUND => self::PLUGIN],
            $pluginList->getNext($frontController, 'dispatch'),
            'Another plugin of ' . $frontController . '::dispatch() runs before the refusal.'
        );
    }
}
