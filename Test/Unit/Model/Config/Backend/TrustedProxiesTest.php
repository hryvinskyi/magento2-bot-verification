<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Config\Backend;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterfaceFactory;
use Hryvinskyi\BotVerification\Model\Config\Backend\TrustedProxies;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Registry;
use PHPUnit\Framework\TestCase;

/**
 * The trusted proxies are stored one CIDR range per line, every line read by the real snapshot builder: blank lines
 * and surrounding whitespace are dropped, an empty value is stored empty, and a line that is not a range refuses the
 * whole value with a message naming the line.
 */
class TrustedProxiesTest extends TestCase
{
    /**
     * Valid lines are stored normalised: trimmed, blank lines dropped, separated by line feeds
     *
     * @dataProvider validValues
     * @param string|null $submitted Value as submitted; null for an emptied field
     * @param string $stored Value as stored
     * @return void
     */
    public function testStoresValidRangesOnePerLine(?string $submitted, string $stored): void
    {
        $setting = $this->setting($submitted);

        $setting->beforeSave();

        self::assertSame($stored, $setting->getData('value'));
    }

    /**
     * Submitted values every line of which is a range, and how they are stored
     *
     * @return array<string, array{string|null, string}>
     */
    public static function validValues(): array
    {
        return [
            'one range' => ['10.0.0.0/8', '10.0.0.0/8'],
            'carriage returns, blank lines and spaces' => [
                " 10.0.0.0/8 \r\n\r\n\t192.0.2.1/32\r\n  \r\n3fff::/20  ",
                "10.0.0.0/8\n192.0.2.1/32\n3fff::/20",
            ],
            'empty' => ['', ''],
            'blank lines only' => ["\r\n  \n", ''],
            'emptied field' => [null, ''],
        ];
    }

    /**
     * A line that is not a CIDR range refuses the value, naming the line as entered without its surrounding
     * whitespace
     *
     * @dataProvider invalidLines
     * @param string $line Line that is not a CIDR range
     * @return void
     */
    public function testRefusesALineThatIsNotARange(string $line): void
    {
        $setting = $this->setting("10.0.0.0/8\n  " . $line . "  \n192.0.2.0/24");

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage(
            sprintf('"%s" is not a CIDR range, e.g. 10.0.0.0/8 or 2001:db8::/32.', $line)
        );
        $setting->beforeSave();
    }

    /**
     * Lines an administrator may type that are not CIDR ranges
     *
     * @return array<string, array{string}>
     */
    public static function invalidLines(): array
    {
        return [
            'an address without a length' => ['192.0.2.1'],
            'a length longer than the address' => ['10.0.0.0/33'],
            'two ranges on one line' => ['10.0.0.0/8, 192.168.0.0/16'],
            'a word' => ['loopback'],
        ];
    }

    /**
     * The setting with a submitted value, over the real snapshot builder
     *
     * @param string|null $submitted Value as submitted
     * @return TrustedProxies
     */
    private function setting(?string $submitted): TrustedProxies
    {
        $context = $this->createMock(Context::class);
        $context->method('getEventDispatcher')->willReturn($this->createMock(ManagerInterface::class));

        $setting = new TrustedProxies(
            $context,
            $this->createMock(Registry::class),
            $this->createMock(ScopeConfigInterface::class),
            $this->createMock(TypeListInterface::class),
            new RangeSnapshotBuilder($this->snapshotFactory())
        );
        $setting->setData('value', $submitted);

        return $setting;
    }

    /**
     * A snapshot factory that builds the snapshot from the arguments it is given
     *
     * @return RangeSnapshotInterfaceFactory
     */
    private function snapshotFactory(): RangeSnapshotInterfaceFactory
    {
        $factory = $this->createMock(RangeSnapshotInterfaceFactory::class);
        $factory->method('create')->willReturnCallback(
            static function (array $data): RangeSnapshotInterface {
                $ipv4Ranges = $data['ipv4Ranges'] ?? null;
                $ipv6Ranges = $data['ipv6Ranges'] ?? null;
                $fetchedAt = $data['fetchedAt'] ?? null;
                self::assertIsString($ipv4Ranges);
                self::assertIsString($ipv6Ranges);
                self::assertIsInt($fetchedAt);

                return new RangeSnapshot($ipv4Ranges, $ipv6Ranges, $fetchedAt);
            }
        );

        return $factory;
    }
}
