<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * Reads the prefixes out of a published address list's document, in one format
 *
 * Each format has its own parser, registered under the format's code with the fetcher that reads the lists. A parser
 * takes the document whole or not at all, and returns the prefixes as published: whether each one is a valid CIDR
 * prefix is for the snapshot builder to judge.
 *
 * @api
 */
interface PrefixListParserInterface
{
    /**
     * The prefixes a published document lists
     *
     * @param string $body The document as fetched
     * @return list<string> Prefixes in the order published; never empty
     * @throws RangeListRejectedException when the document is not in the parser's format or lists no prefix
     */
    public function parse(string $body): array;
}
