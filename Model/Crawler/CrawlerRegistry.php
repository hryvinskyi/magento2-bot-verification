<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Crawler;

use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;

/**
 * The crawlers and published address lists that modules declare, validated once when the registry is built
 *
 * This module declares no crawler and no list of its own: modules contribute them through this class's constructor
 * arguments in their `di.xml`, so with no contributing module enabled the registry is empty, no User-Agent claims
 * anything and nothing is fetched.
 *
 * ```xml
 * <type name="Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry">
 *     <arguments>
 *         <argument name="rangeLists" xsi:type="array">
 *             <item name="example_list" xsi:type="string">https://example.com/crawler.json</item>
 *             <item name="example_edge" xsi:type="string">https://example.com/edge-servers.txt</item>
 *         </argument>
 *         <argument name="crawlers" xsi:type="array">
 *             <item name="example" xsi:type="array">
 *                 <item name="tokens" xsi:type="array">
 *                     <item name="examplebot" xsi:type="string">ExampleBot</item>
 *                 </item>
 *                 <item name="lists" xsi:type="array">
 *                     <item name="example_list" xsi:type="string">example_list</item>
 *                 </item>
 *             </item>
 *         </argument>
 *         <argument name="rangeListFormats" xsi:type="array">
 *             <item name="example_edge" xsi:type="string">plain_text</item>
 *         </argument>
 *         <argument name="proxyLists" xsi:type="array">
 *             <item name="example_edge" xsi:type="string">example_edge</item>
 *         </argument>
 *     </arguments>
 * </type>
 * ```
 *
 * - `rangeLists`: list code => HTTPS URL where the list is published.
 * - `crawlers`: crawler code => its User-Agent `tokens` and the codes of the `lists` that hold its addresses; the item
 *   names inside `tokens` and `lists` only keep contributions from different modules apart.
 * - `rangeListFormats` (optional): list code => format code, for a list not published as the `{"prefixes": [...]}`
 *   JSON document; the registry does not know which formats exist, the code that reads the lists does.
 * - `proxyLists` (optional): codes of the lists whose ranges are trusted proxies - servers that forward a visitor's
 *   request and append the visitor's address to X-Forwarded-For, such as a content delivery network's edge servers.
 *
 * Every code - crawler, list or format - is made of lower-case letters, digits and underscores, and not of digits
 * alone (a code of digits becomes an integer array key). A crawler declares at least one token and at least one list,
 * every token is a non-empty string, and every list a crawler or `proxyLists` names, and every list given a format,
 * is declared in `rangeLists`. Every list is published at an https:// URL. No token belongs to two crawlers, compared
 * case-insensitively: which crawler a User-Agent claims would otherwise depend on the order modules are loaded in.
 * A declaration that breaks any of these fails loudly, naming the crawler or list at fault, rather than being
 * skipped, so a mistake shows the first time the registry is built - by the refresh, the command line or a test.
 *
 * The class name is what contributing modules configure: renaming or moving it breaks every one of them.
 *
 * @api
 */
class CrawlerRegistry implements CrawlerRegistryInterface
{
    private const CODE_PATTERN = '/^[a-z0-9_]+$/';
    private const URL_SCHEME = 'https://';

    /**
     * @var array<string, list<string>> Crawler code => tokens
     */
    private readonly array $tokens;

    /**
     * @var array<string, list<string>> Crawler code => list codes
     */
    private readonly array $crawlerLists;

    /**
     * @var array<string, string> List code => URL
     */
    private readonly array $rangeListUrls;

    /**
     * @var array<string, string> List code => format code, for the lists that declare one
     */
    private readonly array $rangeListFormats;

    /**
     * @var list<string> Codes of the trusted-proxy lists
     */
    private readonly array $proxyListCodes;

    /**
     * @param array<string, array{tokens: array<array-key, string>, lists: array<array-key, string>}> $crawlers
     *        Crawler code => its User-Agent tokens and the codes of the lists that hold its addresses
     * @param array<string, string> $rangeLists List code => HTTPS URL of the published list
     * @param array<string, string> $rangeListFormats List code => format code, for lists in another format than the
     *                                                default
     * @param array<array-key, string> $proxyLists Codes of the lists that hold trusted proxies' addresses
     * @throws \InvalidArgumentException When a declaration breaks a rule stated on the class, naming the crawler or
     *                                   list at fault
     */
    public function __construct(
        array $crawlers = [],
        array $rangeLists = [],
        array $rangeListFormats = [],
        array $proxyLists = []
    ) {
        $this->rangeListUrls = $this->readRangeLists($rangeLists);
        $this->rangeListFormats = $this->readFormats($rangeListFormats);
        $this->proxyListCodes = $this->readProxyLists($proxyLists);

        $tokens = [];
        $crawlerLists = [];
        $owners = [];
        foreach ($crawlers as $key => $definition) {
            $crawlerCode = $this->readCode($key, 'crawler');
            $fields = $this->readDefinition($crawlerCode, $definition);
            $tokens[$crawlerCode] = $this->readTokens($crawlerCode, $fields['tokens'] ?? null);
            $crawlerLists[$crawlerCode] = $this->readCrawlerLists($crawlerCode, $fields['lists'] ?? null);
            foreach ($tokens[$crawlerCode] as $token) {
                $owner = $owners[strtolower($token)] ?? $crawlerCode;
                if ($owner !== $crawlerCode) {
                    throw new \InvalidArgumentException(sprintf(
                        'The User-Agent token "%s" of crawler "%s" is already claimed by crawler "%s".',
                        $token,
                        $crawlerCode,
                        $owner
                    ));
                }
                $owners[strtolower($token)] = $crawlerCode;
            }
        }
        $this->tokens = $tokens;
        $this->crawlerLists = $crawlerLists;
    }

    /**
     * @inheritDoc
     */
    public function getTokens(): array
    {
        return $this->tokens;
    }

    /**
     * @inheritDoc
     */
    public function getRangeListCodes(string $crawlerCode): array
    {
        return $this->crawlerLists[$crawlerCode] ?? [];
    }

    /**
     * @inheritDoc
     */
    public function getRangeListUrls(): array
    {
        return $this->rangeListUrls;
    }

    /**
     * @inheritDoc
     */
    public function getRangeListFormat(string $listCode): string
    {
        return $this->rangeListFormats[$listCode] ?? self::DEFAULT_RANGE_LIST_FORMAT;
    }

    /**
     * @inheritDoc
     */
    public function getProxyListCodes(): array
    {
        return $this->proxyListCodes;
    }

    /**
     * The declared lists, each published at an https:// URL
     *
     * @param array<mixed> $rangeLists List code => URL, as configured
     * @return array<string, string>
     * @throws \InvalidArgumentException When a code is malformed or a URL is not an https:// URL
     */
    private function readRangeLists(array $rangeLists): array
    {
        $urls = [];
        foreach ($rangeLists as $key => $url) {
            $listCode = $this->readCode($key, 'address list');
            if (!is_string($url) || !str_starts_with($url, self::URL_SCHEME)) {
                throw new \InvalidArgumentException(sprintf(
                    'The address list "%s" must be published at an %s URL.',
                    $listCode,
                    self::URL_SCHEME
                ));
            }
            $urls[$listCode] = $url;
        }

        return $urls;
    }

    /**
     * The format codes of the declared lists that name one
     *
     * @param array<mixed> $rangeListFormats List code => format code, as configured
     * @return array<string, string>
     * @throws \InvalidArgumentException When a list is not declared or a format code is malformed
     */
    private function readFormats(array $rangeListFormats): array
    {
        $formats = [];
        foreach ($rangeListFormats as $key => $format) {
            $listCode = $this->readDeclaredList($key, 'given a format');
            $formats[$listCode] = $this->readCode($format, 'format', sprintf(' of address list "%s"', $listCode));
        }

        return $formats;
    }

    /**
     * The codes of the declared lists that hold trusted proxies, each once
     *
     * @param array<mixed> $proxyLists List codes, as configured
     * @return list<string>
     * @throws \InvalidArgumentException When a list is not declared
     */
    private function readProxyLists(array $proxyLists): array
    {
        $codes = [];
        foreach ($proxyLists as $listCode) {
            $codes[$this->readDeclaredList($listCode, 'of trusted proxies')] = true;
        }

        return array_keys($codes);
    }

    /**
     * A crawler's definition, which must be an array
     *
     * @param string $crawlerCode Code of the crawler
     * @param mixed $definition Definition as configured
     * @return array<mixed>
     * @throws \InvalidArgumentException When the definition is not an array
     */
    private function readDefinition(string $crawlerCode, mixed $definition): array
    {
        if (!is_array($definition)) {
            throw new \InvalidArgumentException(sprintf(
                'Crawler "%s" must be declared as an array of "tokens" and "lists".',
                $crawlerCode
            ));
        }

        return $definition;
    }

    /**
     * A crawler's tokens, each a non-empty string, each once (compared case-insensitively)
     *
     * @param string $crawlerCode Code of the crawler
     * @param mixed $tokens Tokens as configured
     * @return list<string>
     * @throws \InvalidArgumentException When there are no tokens or one is not a non-empty string
     */
    private function readTokens(string $crawlerCode, mixed $tokens): array
    {
        if (!is_array($tokens) || $tokens === []) {
            throw new \InvalidArgumentException(sprintf(
                'Crawler "%s" must declare at least one User-Agent token.',
                $crawlerCode
            ));
        }

        $read = [];
        foreach ($tokens as $token) {
            if (!is_string($token) || $token === '') {
                throw new \InvalidArgumentException(sprintf(
                    'Every User-Agent token of crawler "%s" must be a non-empty string.',
                    $crawlerCode
                ));
            }
            $read[strtolower($token)] ??= $token;
        }

        return array_values($read);
    }

    /**
     * The codes of a crawler's lists, each declared, each once
     *
     * @param string $crawlerCode Code of the crawler
     * @param mixed $lists List codes as configured
     * @return list<string>
     * @throws \InvalidArgumentException When there are no lists or one is not declared
     */
    private function readCrawlerLists(string $crawlerCode, mixed $lists): array
    {
        if (!is_array($lists) || $lists === []) {
            throw new \InvalidArgumentException(sprintf(
                'Crawler "%s" must name at least one address list.',
                $crawlerCode
            ));
        }

        $codes = [];
        foreach ($lists as $listCode) {
            $codes[$this->readDeclaredList($listCode, sprintf('of crawler "%s"', $crawlerCode))] = true;
        }

        return array_keys($codes);
    }

    /**
     * The code of a list that `rangeLists` declares
     *
     * @param mixed $listCode Code as configured
     * @param string $role What the list is to whoever names it, for the message
     * @return string
     * @throws \InvalidArgumentException When the code names no declared list
     */
    private function readDeclaredList(mixed $listCode, string $role): string
    {
        if (!is_string($listCode) || !isset($this->rangeListUrls[$listCode])) {
            throw new \InvalidArgumentException(sprintf(
                'The address list "%s" %s is not declared in "rangeLists".',
                is_scalar($listCode) ? (string) $listCode : get_debug_type($listCode),
                $role
            ));
        }

        return $listCode;
    }

    /**
     * A code of lower-case letters, digits and underscores, not of digits alone
     *
     * @param mixed $code Code as configured; an array key arrives as an integer when it is made of digits
     * @param string $kind What the code names, for the message
     * @param string $context Where the code was found, for the message ('' when the code itself says it)
     * @return string
     * @throws \InvalidArgumentException When the code is malformed
     */
    private function readCode(mixed $code, string $kind, string $context = ''): string
    {
        $text = is_int($code) ? (string) $code : $code;
        if (!is_string($text) || preg_match(self::CODE_PATTERN, $text) !== 1 || ctype_digit($text)) {
            throw new \InvalidArgumentException(sprintf(
                'The %s code "%s"%s must be made of lower-case letters, digits and underscores, and not of digits '
                . 'alone.',
                $kind,
                is_scalar($text) ? (string) $text : get_debug_type($text),
                $context
            ));
        }

        return $text;
    }
}
