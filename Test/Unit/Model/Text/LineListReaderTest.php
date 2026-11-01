<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Text;

use Hryvinskyi\BotVerification\Model\Text\LineListReader;
use PHPUnit\Framework\TestCase;

/**
 * A text is read as its lines: split at every line break, trimmed, blank lines dropped, order kept.
 */
class LineListReaderTest extends TestCase
{
    /**
     * The entries read from a text
     *
     * @dataProvider texts
     * @param string $text Text to read
     * @param list<string> $expected Entries it holds
     * @return void
     */
    public function testReadsTheEntriesOfAText(string $text, array $expected): void
    {
        self::assertSame($expected, (new LineListReader())->read($text));
    }

    /**
     * Texts and the entries each holds
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function texts(): array
    {
        return [
            'line feeds' => ["a\nb\nc", ['a', 'b', 'c']],
            'carriage returns and line feeds' => ["a\r\nb\r\n", ['a', 'b']],
            'carriage returns alone' => ["a\rb\r", ['a', 'b']],
            'a mix of breaks' => ["a\nb\r\nc\rd", ['a', 'b', 'c', 'd']],
            'surrounding whitespace and blank lines' => ["  a \n\n\t\n b\t\n", ['a', 'b']],
            'whitespace inside a line is kept' => ["a b\n c  d ", ['a b', 'c  d']],
            'one entry' => ['a', ['a']],
            'empty' => ['', []],
            'blank lines only' => ["\n \r\n\t", []],
            'invalid UTF-8 is not an error' => ["a\xFF\nb", ["a\xFF", 'b']],
        ];
    }

    /**
     * The entries come back as a list: consecutive keys from zero, however many lines were dropped
     *
     * @return void
     */
    public function testTheResultIsAList(): void
    {
        self::assertSame([0, 1], array_keys((new LineListReader())->read("\n\na\n\nb\n")));
    }
}
