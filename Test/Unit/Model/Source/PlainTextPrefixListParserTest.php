<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Source;

use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Source\PlainTextPrefixListParser;
use PHPUnit\Framework\TestCase;

/**
 * The plain-text parser takes one prefix per line and rejects a document without a single one.
 */
class PlainTextPrefixListParserTest extends TestCase
{
    /**
     * Each non-blank line, trimmed, is a prefix, in the order published
     *
     * @dataProvider documents
     * @param string $body Document as published
     * @param list<string> $expected Prefixes it lists
     * @return void
     */
    public function testReadsOnePrefixPerLine(string $body, array $expected): void
    {
        self::assertSame($expected, (new PlainTextPrefixListParser())->parse($body));
    }

    /**
     * Documents and the prefixes each lists
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function documents(): array
    {
        return [
            'line feeds' => ["192.0.2.0/24\n198.51.100.0/22\n", ['192.0.2.0/24', '198.51.100.0/22']],
            'carriage returns and line feeds' => [
                "192.0.2.0/24\r\n198.51.100.0/22\r\n",
                ['192.0.2.0/24', '198.51.100.0/22'],
            ],
            'no line feed after the last line' => [
                "192.0.2.0/24\n198.51.100.0/22",
                ['192.0.2.0/24', '198.51.100.0/22'],
            ],
            'blank lines and surrounding spaces are skipped' => [
                "\n  192.0.2.0/24  \n\n\t198.51.100.0/22\r\n   \r\n",
                ['192.0.2.0/24', '198.51.100.0/22'],
            ],
            'both families mixed' => [
                "2001:db8:4800::/40\n192.0.2.0/24\n2001:db8:4801::/48\n",
                ['2001:db8:4800::/40', '192.0.2.0/24', '2001:db8:4801::/48'],
            ],
            'a line is not checked here' => ["<html>\n", ['<html>']],
        ];
    }

    /**
     * A document without a single prefix is rejected
     *
     * @dataProvider emptyDocuments
     * @param string $body Document as published
     * @return void
     */
    public function testRejectsADocumentWithoutPrefixes(string $body): void
    {
        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage('The published address list holds no prefixes.');

        (new PlainTextPrefixListParser())->parse($body);
    }

    /**
     * Documents that list nothing
     *
     * @return array<string, array{string}>
     */
    public static function emptyDocuments(): array
    {
        return [
            'an empty body' => [''],
            'whitespace only' => [" \n\r\n\t\n  "],
        ];
    }
}
