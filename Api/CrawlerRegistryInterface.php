<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * The crawlers a User-Agent can claim to be, and the published address lists their addresses are checked against
 *
 * Besides crawler lists it holds the lists of trusted proxies: servers in front of the shop that forward a visitor's
 * request and append the visitor's address to X-Forwarded-For. Every declared list is refreshed the same way.
 */
interface CrawlerRegistryInterface
{
    /**
     * Format code of a list whose declaring module named no format: the `{"prefixes": [...]}` JSON document most
     * crawler owners publish
     */
    public const DEFAULT_RANGE_LIST_FORMAT = 'prefix_json';

    /**
     * The User-Agent tokens of every crawler
     *
     * @return array<string, list<string>> Crawler code => its tokens, in declaration order
     */
    public function getTokens(): array;

    /**
     * Codes of the address lists a crawler's addresses are checked against
     *
     * @param string $crawlerCode Code of the crawler
     * @return list<string> List codes in declaration order; [] for an unknown crawler
     */
    public function getRangeListCodes(string $crawlerCode): array;

    /**
     * Where every declared list is published, crawler lists and trusted-proxy lists alike
     *
     * @return array<string, string> List code => HTTPS URL of the published list, in declaration order
     */
    public function getRangeListUrls(): array;

    /**
     * Format code of a list, telling how its published document is read
     *
     * @param string $listCode Code of the list
     * @return string The format its declaring module named; DEFAULT_RANGE_LIST_FORMAT when it named none
     */
    public function getRangeListFormat(string $listCode): string;

    /**
     * Codes of the lists that hold the addresses of trusted proxies (such as a content delivery network's edge
     * servers)
     *
     * @return list<string> List codes in declaration order, each once
     */
    public function getProxyListCodes(): array;
}
