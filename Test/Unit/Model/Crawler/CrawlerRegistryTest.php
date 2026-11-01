<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Crawler;

use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use PHPUnit\Framework\TestCase;

/**
 * The registry takes the crawlers and lists modules declare, normalised, and refuses a malformed declaration as a
 * whole, naming the crawler or list at fault.
 */
class CrawlerRegistryTest extends TestCase
{
    private const EXAMPLE_URL = 'https://bot.example/example.json';

    /**
     * A valid declaration is kept in declaration order, with the item names of tokens and lists dropped
     *
     * @return void
     */
    public function testKeepsAValidDeclaration(): void
    {
        $registry = new CrawlerRegistry(
            [
                'example' => [
                    'tokens' => ['examplebot' => 'ExampleBot', 'image' => 'ExampleBot-Image'],
                    'lists' => ['main' => 'example_main', 'extra' => 'example_extra'],
                ],
                'other' => [
                    'tokens' => ['OtherBot', 'other-fetcher'],
                    'lists' => ['other_list'],
                ],
            ],
            [
                'example_main' => 'https://bot.example/main.json',
                'example_extra' => 'https://bot.example/extra.json',
                'other_list' => 'https://other.example/list.json',
            ]
        );

        self::assertSame(
            ['example' => ['ExampleBot', 'ExampleBot-Image'], 'other' => ['OtherBot', 'other-fetcher']],
            $registry->getTokens()
        );
        self::assertSame(['example_main', 'example_extra'], $registry->getRangeListCodes('example'));
        self::assertSame(['other_list'], $registry->getRangeListCodes('other'));
        self::assertSame(
            [
                'example_main' => 'https://bot.example/main.json',
                'example_extra' => 'https://bot.example/extra.json',
                'other_list' => 'https://other.example/list.json',
            ],
            $registry->getRangeListUrls()
        );
        self::assertSame([], $registry->getProxyListCodes());
    }

    /**
     * An unknown crawler has no lists
     *
     * @return void
     */
    public function testAnUnknownCrawlerHasNoLists(): void
    {
        $registry = new CrawlerRegistry(
            ['example' => ['tokens' => ['ExampleBot'], 'lists' => ['example_list']]],
            ['example_list' => self::EXAMPLE_URL]
        );

        self::assertSame([], $registry->getRangeListCodes('unknown'));
    }

    /**
     * A token or list repeated within one crawler is kept once; tokens are compared case-insensitively
     *
     * @return void
     */
    public function testKeepsARepeatWithinOneCrawlerOnce(): void
    {
        $registry = new CrawlerRegistry(
            [
                'example' => [
                    'tokens' => ['first' => 'ExampleBot', 'again' => 'examplebot', 'image' => 'ExampleBot-Image'],
                    'lists' => ['first' => 'example_list', 'again' => 'example_list'],
                ],
            ],
            ['example_list' => self::EXAMPLE_URL]
        );

        self::assertSame(['example' => ['ExampleBot', 'ExampleBot-Image']], $registry->getTokens());
        self::assertSame(['example_list'], $registry->getRangeListCodes('example'));
    }

    /**
     * With no module contributing anything, the registry is empty and valid
     *
     * @return void
     */
    public function testAnEmptyRegistryIsValid(): void
    {
        $registry = new CrawlerRegistry();

        self::assertSame([], $registry->getTokens());
        self::assertSame([], $registry->getRangeListUrls());
        self::assertSame([], $registry->getProxyListCodes());
        self::assertSame([], $registry->getRangeListCodes('example'));
    }

    /**
     * A list without a declared format is in the default format; a declared format is kept
     *
     * @return void
     */
    public function testReportsTheFormatOfEachList(): void
    {
        $registry = new CrawlerRegistry(
            ['example' => ['tokens' => ['ExampleBot'], 'lists' => ['example_list']]],
            ['example_list' => self::EXAMPLE_URL, 'edge_ipv4' => 'https://edge.example/ipv4.txt'],
            ['edge_ipv4' => 'plain_text']
        );

        self::assertSame('plain_text', $registry->getRangeListFormat('edge_ipv4'));
        self::assertSame('prefix_json', $registry->getRangeListFormat('example_list'));
        self::assertSame(
            CrawlerRegistryInterface::DEFAULT_RANGE_LIST_FORMAT,
            $registry->getRangeListFormat('example_list')
        );
    }

    /**
     * Lists of trusted proxies are reported in declaration order, each once, and need no crawler
     *
     * @return void
     */
    public function testReportsTheListsOfTrustedProxies(): void
    {
        $registry = new CrawlerRegistry(
            [],
            ['edge_ipv4' => 'https://edge.example/ipv4.txt', 'edge_ipv6' => 'https://edge.example/ipv6.txt'],
            ['edge_ipv4' => 'plain_text', 'edge_ipv6' => 'plain_text'],
            ['ipv4' => 'edge_ipv4', 'ipv6' => 'edge_ipv6', 'from_another_module' => 'edge_ipv4']
        );

        self::assertSame(['edge_ipv4', 'edge_ipv6'], $registry->getProxyListCodes());
        self::assertSame(
            ['edge_ipv4' => 'https://edge.example/ipv4.txt', 'edge_ipv6' => 'https://edge.example/ipv6.txt'],
            $registry->getRangeListUrls()
        );
        self::assertSame([], $registry->getTokens());
    }

    /**
     * A malformed declaration is refused when the registry is built, with a message naming what is at fault
     *
     * The arguments are passed the way the container passes them, untyped, since a malformed declaration in `di.xml`
     * is exactly what the registry has to catch.
     *
     * @dataProvider malformedDeclarations
     * @param array<string, mixed> $arguments Constructor arguments by name
     * @param string $named Text the message must contain
     * @return void
     */
    public function testRefusesAMalformedDeclaration(array $arguments, string $named): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($named);

        (new \ReflectionClass(CrawlerRegistry::class))->newInstanceArgs($arguments);
    }

    /**
     * Each rule of the registry, broken once
     *
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function malformedDeclarations(): array
    {
        $lists = ['example_list' => self::EXAMPLE_URL];
        $valid = ['tokens' => ['ExampleBot'], 'lists' => ['example_list']];

        return [
            'crawler code with capitals' => [
                ['crawlers' => ['Example' => $valid], 'rangeLists' => $lists],
                'crawler code "Example"',
            ],
            'crawler code with a hyphen' => [
                ['crawlers' => ['example-bot' => $valid], 'rangeLists' => $lists],
                'crawler code "example-bot"',
            ],
            'crawler code of digits only' => [
                ['crawlers' => ['123' => $valid], 'rangeLists' => $lists],
                'crawler code "123"',
            ],
            'crawler code with a trailing line feed' => [
                ['crawlers' => ["example\n" => $valid], 'rangeLists' => $lists],
                "crawler code \"example\n\"",
            ],
            'list code with a trailing line feed' => [
                ['rangeLists' => ["example_list\n" => self::EXAMPLE_URL]],
                "address list code \"example_list\n\"",
            ],
            'format code with a trailing line feed' => [
                ['rangeLists' => $lists, 'rangeListFormats' => ['example_list' => "plain_text\n"]],
                "The format code \"plain_text\n\" of address list \"example_list\"",
            ],
            'empty crawler code' => [
                ['crawlers' => ['' => $valid], 'rangeLists' => $lists],
                'crawler code ""',
            ],
            'list code with capitals' => [
                ['rangeLists' => ['Example_List' => self::EXAMPLE_URL]],
                'address list code "Example_List"',
            ],
            'list code of digits only' => [
                ['rangeLists' => ['2024' => self::EXAMPLE_URL]],
                'address list code "2024"',
            ],
            'crawler declared as a string' => [
                ['crawlers' => ['example' => 'ExampleBot'], 'rangeLists' => $lists],
                'Crawler "example" must be declared as an array',
            ],
            'crawler without tokens' => [
                ['crawlers' => ['example' => ['lists' => ['example_list']]], 'rangeLists' => $lists],
                'Crawler "example" must declare at least one User-Agent token',
            ],
            'crawler with no token' => [
                ['crawlers' => ['example' => ['tokens' => [], 'lists' => ['example_list']]], 'rangeLists' => $lists],
                'Crawler "example" must declare at least one User-Agent token',
            ],
            'tokens given as a string' => [
                [
                    'crawlers' => ['example' => ['tokens' => 'ExampleBot', 'lists' => ['example_list']]],
                    'rangeLists' => $lists,
                ],
                'Crawler "example" must declare at least one User-Agent token',
            ],
            'crawler without lists' => [
                ['crawlers' => ['example' => ['tokens' => ['ExampleBot']]], 'rangeLists' => $lists],
                'Crawler "example" must name at least one address list',
            ],
            'crawler with no list' => [
                ['crawlers' => ['example' => ['tokens' => ['ExampleBot'], 'lists' => []]], 'rangeLists' => $lists],
                'Crawler "example" must name at least one address list',
            ],
            'token that is not a string' => [
                ['crawlers' => ['example' => ['tokens' => [42], 'lists' => ['example_list']]], 'rangeLists' => $lists],
                'Every User-Agent token of crawler "example" must be a non-empty string',
            ],
            'empty token' => [
                ['crawlers' => ['example' => ['tokens' => [''], 'lists' => ['example_list']]], 'rangeLists' => $lists],
                'Every User-Agent token of crawler "example" must be a non-empty string',
            ],
            'list a crawler names but nobody declares' => [
                [
                    'crawlers' => ['example' => ['tokens' => ['ExampleBot'], 'lists' => ['missing']]],
                    'rangeLists' => $lists,
                ],
                'The address list "missing" of crawler "example" is not declared',
            ],
            'list a crawler names that is not a code' => [
                [
                    'crawlers' => ['example' => ['tokens' => ['ExampleBot'], 'lists' => [['example_list']]]],
                    'rangeLists' => $lists,
                ],
                'The address list "array" of crawler "example" is not declared',
            ],
            'list published over plain HTTP' => [
                ['rangeLists' => ['example_list' => 'http://bot.example/example.json']],
                'The address list "example_list" must be published at an https:// URL',
            ],
            'list published at a relative address' => [
                ['rangeLists' => ['example_list' => '//bot.example/example.json']],
                'The address list "example_list" must be published at an https:// URL',
            ],
            'list without a URL' => [
                ['rangeLists' => ['example_list' => null]],
                'The address list "example_list" must be published at an https:// URL',
            ],
            'token claimed by two crawlers, in another case' => [
                [
                    'crawlers' => [
                        'example' => $valid,
                        'other' => ['tokens' => ['OtherBot', 'examplebot'], 'lists' => ['example_list']],
                    ],
                    'rangeLists' => $lists,
                ],
                'The User-Agent token "examplebot" of crawler "other" is already claimed by crawler "example"',
            ],
            'format of an undeclared list' => [
                ['rangeLists' => $lists, 'rangeListFormats' => ['missing' => 'plain_text']],
                'The address list "missing" given a format is not declared',
            ],
            'malformed format code' => [
                ['rangeLists' => $lists, 'rangeListFormats' => ['example_list' => 'Plain-Text']],
                'The format code "Plain-Text" of address list "example_list"',
            ],
            'format that is not a code' => [
                ['rangeLists' => $lists, 'rangeListFormats' => ['example_list' => ['plain_text']]],
                'The format code "array" of address list "example_list"',
            ],
            'proxy list nobody declares' => [
                ['rangeLists' => $lists, 'proxyLists' => ['edge' => 'missing']],
                'The address list "missing" of trusted proxies is not declared',
            ],
            'proxy list that is not a code' => [
                ['rangeLists' => $lists, 'proxyLists' => ['edge' => 7]],
                'The address list "7" of trusted proxies is not declared',
            ],
        ];
    }
}
