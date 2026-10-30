<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Config;

use Hryvinskyi\BotVerification\Block\Adminhtml\System\Config\AddressCheck;
use Hryvinskyi\BotVerification\Model\Config\Backend\TrustedProxies;
use Magento\Config\Model\Config;
use Magento\Config\Model\Config\Structure;
use Magento\Config\Model\Config\Structure\Element\Field;
use Magento\Config\Model\Config\Structure\Element\Section;
use Magento\Framework\Exception\LocalizedException;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The module's admin section as the admin renders and saves it: through the merged configuration structure and the
 * configuration model the admin save runs, never through the declaring file.
 *
 * @magentoAppArea adminhtml
 */
class StructureTest extends TestCase
{
    private const SECTION = 'hryvinskyi_bot_verification';

    /**
     * The section sits in the shared extensions tab and is guarded by the module's permission
     *
     * @return void
     */
    public function testTheSectionIsInTheSharedTab(): void
    {
        $section = self::structure()->getElement(self::SECTION);

        self::assertInstanceOf(Section::class, $section);
        self::assertSame('hryvinskyi', $section->getAttribute('tab'));
        self::assertSame('Hryvinskyi_BotVerification::config', $section->getAttribute('resource'));
    }

    /**
     * The trusted proxies are a default-scope field whose value is checked by the module's backend model
     *
     * @return void
     */
    public function testTheTrustedProxiesAreCheckedByTheBackendModel(): void
    {
        $field = self::structure()->getElement(self::SECTION . '/client_address/trusted_proxies');

        self::assertInstanceOf(Field::class, $field);
        self::assertTrue($field->hasBackendModel());
        self::assertInstanceOf(TrustedProxies::class, $field->getBackendModel());
        self::assertTrue($field->showInDefault());
        self::assertFalse($field->showInWebsite());
        self::assertFalse($field->showInStore());
    }

    /**
     * The address check is a default-scope row rendered by the module's block
     *
     * @return void
     */
    public function testTheAddressCheckIsRenderedByTheBlock(): void
    {
        $field = self::structure()->getElement(self::SECTION . '/client_address/address_check');

        self::assertInstanceOf(Field::class, $field);
        self::assertSame(AddressCheck::class, $field->getFrontendModel());
        self::assertFalse($field->hasBackendModel());
        self::assertTrue($field->showInDefault());
        self::assertFalse($field->showInWebsite());
    }

    /**
     * The refusal switch is a yes/no choice an administrator can make for the whole shop, a website or a store
     *
     * @return void
     */
    public function testTheRefusalSwitchIsAYesNoChoiceInEveryScope(): void
    {
        $field = self::structure()->getElement(self::SECTION . '/impostor/refuse');

        self::assertInstanceOf(Field::class, $field);
        self::assertSame(
            [1, 0],
            array_column($field->getOptions(), 'value')
        );
        self::assertTrue($field->showInDefault());
        self::assertTrue($field->showInWebsite());
        self::assertTrue($field->showInStore());
    }

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

    /**
     * The merged configuration structure the admin renders from
     *
     * @return Structure
     */
    private static function structure(): Structure
    {
        $structure = Bootstrap::getObjectManager()->get(Structure::class);
        self::assertInstanceOf(Structure::class, $structure);

        return $structure;
    }
}
