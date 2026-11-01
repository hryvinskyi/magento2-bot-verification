<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * The one reading of a text that holds one entry per line, such as a multi-line administrator value or a published
 * list of address ranges
 *
 * Whatever stores such a text and whatever reads it back use this reading, so they cannot disagree about which
 * lines are entries.
 *
 * @api
 */
interface LineListReaderInterface
{
    /**
     * The entries of a text: its lines, in order, trimmed, without the blank ones
     *
     * A line ends at any line break (a line feed, a carriage return, both together, and the other breaks a regular
     * expression's `\R` matches). A text without an entry gives an empty list.
     *
     * @param string $text Text to read
     * @return list<string>
     */
    public function read(string $text): array;
}
