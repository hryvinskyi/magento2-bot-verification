<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Source;

use Hryvinskyi\BotVerification\Api\PrefixListParserInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * Reads a document that lists one CIDR prefix per line, as a content delivery network publishes its edge servers
 *
 * Lines end in a line feed, with or without a carriage return before it; whitespace around a line is trimmed and blank
 * lines are skipped. Whatever else a line holds is taken as its prefix, for the snapshot builder to accept or reject,
 * so a document that is not such a list (an HTML page, say) is rejected there. A document without a single prefix is
 * rejected here.
 */
class PlainTextPrefixListParser implements PrefixListParserInterface
{
    /**
     * @inheritDoc
     */
    public function parse(string $body): array
    {
        $prefixes = [];
        foreach (explode("\n", $body) as $line) {
            $prefix = trim($line);
            if ($prefix !== '') {
                $prefixes[] = $prefix;
            }
        }
        if ($prefixes === []) {
            throw new RangeListRejectedException(__('The published address list holds no prefixes.'));
        }

        return $prefixes;
    }
}
