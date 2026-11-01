<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model;

use Hryvinskyi\BotVerification\Api\LineListReaderInterface;
use Hryvinskyi\BotVerification\Model\Config;
use Hryvinskyi\BotVerification\Model\Text\LineListReader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use PHPUnit\Framework\TestCase;

/**
 * The trusted proxies are read at the default scope, one range per line, without blank lines or surrounding
 * whitespace; the refusal switch is read for the current store.
 */
class ConfigTest extends TestCase
{
    /**
     * The stored value is split into trimmed lines, blank ones dropped, whatever the line endings
     *
     * @dataProvider storedValues
     * @param string|null $stored Value as stored
     * @param list<string> $expected Ranges read from it
     * @return void
     */
    public function testReadsOneRangePerLine(?string $stored, array $expected): void
    {
        self::assertSame($expected, $this->config($stored)->getTrustedProxies());
    }

    /**
     * Stored values and the ranges read from them
     *
     * @return array<string, array{string|null, list<string>}>
     */
    public static function storedValues(): array
    {
        return [
            'line feeds' => ["127.0.0.0/8\n::1/128\n10.0.0.0/8", ['127.0.0.0/8', '::1/128', '10.0.0.0/8']],
            'carriage returns and line feeds' => ["127.0.0.0/8\r\n10.0.0.0/8\r\n", ['127.0.0.0/8', '10.0.0.0/8']],
            'surrounding whitespace and blank lines' => [
                "  127.0.0.0/8 \n\n\t\n 10.0.0.0/8\t\n",
                ['127.0.0.0/8', '10.0.0.0/8'],
            ],
            'one range' => ['192.0.2.1/32', ['192.0.2.1/32']],
            'empty' => ['', []],
            'blank lines only' => ["\n \r\n", []],
            'never stored' => [null, []],
        ];
    }

    /**
     * The value is read at the default scope, under the module's path
     *
     * @return void
     */
    public function testReadsTheDefaultScope(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('getValue')
            ->with(
                'hryvinskyi_bot_verification/client_address/trusted_proxies',
                ScopeConfigInterface::SCOPE_TYPE_DEFAULT
            )
            ->willReturn('10.0.0.0/8');

        self::assertSame(['10.0.0.0/8'], (new Config($scopeConfig, new LineListReader()))->getTrustedProxies());
    }

    /**
     * The stored text is split by the one reader of multi-line values, and what it returns is what the settings give
     *
     * @return void
     */
    public function testSplitsTheValueWithTheLineListReader(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn("a\nb");
        $reader = $this->createMock(LineListReaderInterface::class);
        $reader->expects(self::once())->method('read')->with("a\nb")->willReturn(['read by the reader']);

        self::assertSame(['read by the reader'], (new Config($scopeConfig, $reader))->getTrustedProxies());
    }

    /**
     * Nothing is stored as text, so there is nothing to split
     *
     * @return void
     */
    public function testNeverAsksTheReaderAboutAValueThatIsNotText(): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn(null);
        $reader = $this->createMock(LineListReaderInterface::class);
        $reader->expects(self::never())->method('read');

        self::assertSame([], (new Config($scopeConfig, $reader))->getTrustedProxies());
    }

    /**
     * The refusal switch is read as a flag for the current store, under the module's path
     *
     * @dataProvider switchStates
     * @param bool $isSet Whether the stored flag is set
     * @return void
     */
    public function testReadsTheRefusalSwitchForTheCurrentStore(bool $isSet): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->expects(self::once())
            ->method('isSetFlag')
            ->with('hryvinskyi_bot_verification/impostor/refuse', ScopeInterface::SCOPE_STORE)
            ->willReturn($isSet);

        self::assertSame($isSet, (new Config($scopeConfig, new LineListReader()))->isImpostorRefusalEnabled());
    }

    /**
     * Both states of the stored flag
     *
     * @return array<string, array{bool}>
     */
    public static function switchStates(): array
    {
        return [
            'on' => [true],
            'off' => [false],
        ];
    }

    /**
     * The settings over a stored value
     *
     * @param string|null $stored Value as stored; null when nothing is
     * @return Config
     */
    private function config(?string $stored): Config
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($stored);

        return new Config($scopeConfig, new LineListReader());
    }
}
