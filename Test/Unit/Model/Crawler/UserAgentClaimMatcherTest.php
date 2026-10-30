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
use Hryvinskyi\BotVerification\Model\Crawler\UserAgentClaimMatcher;
use PHPUnit\Framework\TestCase;

/**
 * A User-Agent claims every crawler one of whose tokens occurs in it, each once, in order of first occurrence; at one
 * position only the longest token counts.
 */
class UserAgentClaimMatcherTest extends TestCase
{
    private const SMARTPHONE = 'Mozilla/5.0 (Linux; Android 6.0.1; Nexus 5X Build/MMB29P) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/129.0.6668.89 Mobile Safari/537.36 (compatible; ExampleBot/2.1; '
        . '+http://bot.example/)';
    private const BROWSER = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';

    /**
     * Each User-Agent claims the crawlers whose tokens it names
     *
     * @dataProvider userAgents
     * @param string $userAgent Raw User-Agent
     * @param list<string> $expected Claimed crawler codes
     * @return void
     */
    public function testFindsTheClaimedCrawlers(string $userAgent, array $expected): void
    {
        self::assertSame($expected, self::matcher()->claims($userAgent));
    }

    /**
     * User-Agents shaped like real crawlers', browsers', and the edge cases of matching
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function userAgents(): array
    {
        return [
            'crawler token inside a compatible clause' => [
                'Mozilla/5.0 (compatible; ExampleBot/2.1; +http://bot.example/)',
                ['example'],
            ],
            'smartphone User-Agent with the token at the end' => [self::SMARTPHONE, ['example']],
            'token at the start' => ['OtherBot (+http://bot.example/other)', ['other']],
            'token in capitals' => ['Mozilla/5.0 (compatible; EXAMPLEBOT/2.1)', ['example']],
            'token in lower case' => ['other-fetcher/1.0', ['other']],
            'token in mixed case' => ['Other-Fetcher/1.0', ['other']],
            'token inside a longer word' => ['NotAnExampleBotAtAll', ['example']],
            'two crawlers, in order of occurrence' => [
                'OtherBot/1.0 (compatible; ExampleBot/2.1)',
                ['other', 'example'],
            ],
            'two crawlers, the other way round' => [
                'ExampleBot/2.1 (compatible; OtherBot/1.0)',
                ['example', 'other'],
            ],
            'one crawler named twice by different tokens' => ['other-fetcher OtherBot', ['other']],
            'one crawler named again after another' => [
                'ExampleBot/2.1 OtherBot/1.0 ExampleBot/2.1',
                ['example', 'other'],
            ],
            'invalid UTF-8 does not hide the token' => ["ExampleBot/2.1 \xff\xfe", ['example']],
            'browser' => [self::BROWSER, []],
            'empty' => ['', []],
        ];
    }

    /**
     * Where two tokens start at the same position, only the longer one counts
     *
     * @return void
     */
    public function testTheLongestTokenWinsAtOnePosition(): void
    {
        $matcher = new UserAgentClaimMatcher(self::registry([
            'example' => ['ExampleBot'],
            'image' => ['ExampleBot-Image'],
        ]));

        self::assertSame(['image'], $matcher->claims('Mozilla/5.0 (compatible; ExampleBot-Image/1.0)'));
        self::assertSame(['image', 'example'], $matcher->claims('ExampleBot-Image/1.0 ExampleBot/2.1'));
        self::assertSame(['example'], $matcher->claims('ExampleBot/2.1'));
    }

    /**
     * A token written as the tail of another token still occurs: one token cannot hide another
     *
     * @return void
     */
    public function testATokenInsideAnotherStillCounts(): void
    {
        $matcher = new UserAgentClaimMatcher(self::registry([
            'example' => ['ExampleBot'],
            'fetcher' => ['Fetcher-Example'],
        ]));

        self::assertSame(
            ['fetcher', 'example'],
            $matcher->claims('Mozilla/5.0 (compatible; Fetcher-ExampleBot/2.1)')
        );
    }

    /**
     * Regular-expression metacharacters and the pattern's delimiter in a token are matched literally
     *
     * @return void
     */
    public function testMatchesMetacharactersLiterally(): void
    {
        $matcher = new UserAgentClaimMatcher(self::registry([
            'meta' => ['Meta.Bot+(x)'],
            'tilde' => ['Tilde~Bot'],
        ]));

        self::assertSame(['meta'], $matcher->claims('Meta.Bot+(x)/1.0'));
        self::assertSame([], $matcher->claims('MetaXBot+(x)/1.0'));
        self::assertSame([], $matcher->claims('Meta.Bottt(x)/1.0'));
        self::assertSame(['tilde'], $matcher->claims('Tilde~Bot/1.0'));
    }

    /**
     * With no crawler declared, nothing is claimed
     *
     * @return void
     */
    public function testAnEmptyRegistryClaimsNothing(): void
    {
        $matcher = new UserAgentClaimMatcher(new CrawlerRegistry());

        self::assertSame([], $matcher->claims('Mozilla/5.0 (compatible; ExampleBot/2.1)'));
    }

    /**
     * The tokens are read once, when the first non-empty User-Agent is asked about, and never when built
     *
     * @return void
     */
    public function testReadsTheTokensOnceAndOnlyWhenNeeded(): void
    {
        $registry = $this->createMock(CrawlerRegistryInterface::class);
        $registry->expects(self::once())->method('getTokens')->willReturn(['example' => ['ExampleBot']]);

        $matcher = new UserAgentClaimMatcher($registry);
        self::assertSame([], $matcher->claims(''));
        self::assertSame(['example'], $matcher->claims('ExampleBot/2.1'));
        self::assertSame([], $matcher->claims(self::BROWSER));
        self::assertSame(['example'], $matcher->claims('ExampleBot/2.1'));
    }

    /**
     * Asking about different User-Agents in turn answers each one for itself
     *
     * @return void
     */
    public function testAnswersEachUserAgentInTurn(): void
    {
        $matcher = self::matcher();

        self::assertSame(['example'], $matcher->claims('ExampleBot/2.1'));
        self::assertSame(['example'], $matcher->claims('ExampleBot/2.1'));
        self::assertSame(['other'], $matcher->claims('OtherBot/1.0'));
        self::assertSame([], $matcher->claims(self::BROWSER));
        self::assertSame(['example'], $matcher->claims('ExampleBot/2.1'));
    }

    /**
     * A matcher over two neutral crawlers
     *
     * @return UserAgentClaimMatcher
     */
    private static function matcher(): UserAgentClaimMatcher
    {
        return new UserAgentClaimMatcher(self::registry([
            'example' => ['ExampleBot', 'ExampleBot-Mobile'],
            'other' => ['OtherBot', 'other-fetcher'],
        ]));
    }

    /**
     * A registry of crawlers with the given tokens, each with a list of its own
     *
     * @param array<string, list<string>> $tokens Crawler code => tokens
     * @return CrawlerRegistry
     */
    private static function registry(array $tokens): CrawlerRegistry
    {
        $crawlers = [];
        $rangeLists = [];
        foreach ($tokens as $crawlerCode => $crawlerTokens) {
            $crawlers[$crawlerCode] = ['tokens' => $crawlerTokens, 'lists' => [$crawlerCode . '_list']];
            $rangeLists[$crawlerCode . '_list'] = 'https://bot.example/' . $crawlerCode . '.json';
        }

        return new CrawlerRegistry($crawlers, $rangeLists);
    }
}
