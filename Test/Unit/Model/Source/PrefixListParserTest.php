<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Source;

use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Source\PrefixListParser;
use Magento\Framework\Serialize\Serializer\Json;
use PHPUnit\Framework\TestCase;

/**
 * The JSON parser reads the `{"prefixes": [...]}` document whole, and rejects any other shape saying what was wrong.
 */
class PrefixListParserTest extends TestCase
{
    /**
     * The prefixes of a well-formed document come out as published, in order
     *
     * @dataProvider wellFormedDocuments
     * @param string $body Document as published
     * @param list<string> $expected Prefixes it lists
     * @return void
     */
    public function testReadsTheListedPrefixes(string $body, array $expected): void
    {
        self::assertSame($expected, self::parser()->parse($body));
    }

    /**
     * Documents of the expected shape and the prefixes each lists
     *
     * @return array<string, array{string, list<string>}>
     */
    public static function wellFormedDocuments(): array
    {
        return [
            'both families, with a creation time' => [
                '{"creationTime": "2026-10-30T06:00:00.000000", "prefixes": ['
                . '{"ipv6Prefix": "2001:db8:4801:7428::/64"}, {"ipv4Prefix": "192.0.2.0/27"}, '
                . '{"ipv4Prefix": "198.51.100.64/27"}]}',
                ['2001:db8:4801:7428::/64', '192.0.2.0/27', '198.51.100.64/27'],
            ],
            'IPv4 only' => [
                '{"creationTime": "2024-01-03T11:30:00.121331", "prefixes": [{"ipv4Prefix": "203.0.113.0/24"}]}',
                ['203.0.113.0/24'],
            ],
            'other keys in the document and in an entry are ignored' => [
                '{"synctoken": "1730268000", "notes": "updated weekly", "prefixes": ['
                . '{"ipv4Prefix": "192.0.2.0/24", "service": "crawler", "scope": "global"}]}',
                ['192.0.2.0/24'],
            ],
            'an entry holding both keys gives both prefixes' => [
                '{"prefixes": [{"ipv4Prefix": "192.0.2.0/24", "ipv6Prefix": "2001:db8::/32"}]}',
                ['192.0.2.0/24', '2001:db8::/32'],
            ],
            'a prefix is not checked here, only that it is text' => [
                '{"prefixes": [{"ipv4Prefix": "not a prefix"}]}',
                ['not a prefix'],
            ],
        ];
    }

    /**
     * A document of any other shape is rejected whole, with the reason
     *
     * @dataProvider malformedDocuments
     * @param string $body Document as published
     * @param string $reason Expected reason
     * @return void
     */
    public function testRejectsAMalformedDocument(string $body, string $reason): void
    {
        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage($reason);

        self::parser()->parse($body);
    }

    /**
     * Documents the parser rejects and why
     *
     * @return array<string, array{string, string}>
     */
    public static function malformedDocuments(): array
    {
        $notJson = 'The published address list is not valid JSON.';
        $noPrefixes = 'The published address list is not a JSON object with a "prefixes" entry.';
        $notAList = 'The "prefixes" entry of the published address list is not a list.';

        return [
            'invalid JSON' => ['{"prefixes": [', $notJson],
            'an empty body' => ['', $notJson],
            'an HTML page' => ['<html><body>Moved</body></html>', $notJson],
            'an empty JSON list' => ['[]', $noPrefixes],
            'an empty JSON object' => ['{}', $noPrefixes],
            'a JSON string' => ['"192.0.2.0/24"', $noPrefixes],
            'prefixes as text' => ['{"prefixes": "192.0.2.0/24"}', $notAList],
            'prefixes as an object with keys' => ['{"prefixes": {"a": {"ipv4Prefix": "192.0.2.0/24"}}}', $notAList],
            'prefixes as an empty object' => [
                '{"prefixes": {}}',
                'The published address list holds no prefixes.',
            ],
            'an empty prefixes list' => [
                '{"creationTime": "2026-10-30T06:00:00", "prefixes": []}',
                'The published address list holds no prefixes.',
            ],
            'an empty entry' => [
                '{"prefixes": [{"ipv4Prefix": "192.0.2.0/24"}, {}]}',
                'Entry 2 of the published address list holds neither an "ipv4Prefix" nor an "ipv6Prefix".',
            ],
            'an entry with another key only' => [
                '{"prefixes": [{"prefix": "192.0.2.0/24"}]}',
                'Entry 1 of the published address list holds neither an "ipv4Prefix" nor an "ipv6Prefix".',
            ],
            'a number as a prefix' => [
                '{"prefixes": [{"ipv4Prefix": 5}]}',
                'Entry 1 of the published address list holds a value under "ipv4Prefix" that is not text.',
            ],
            'null as a prefix' => [
                '{"prefixes": [{"ipv6Prefix": null}]}',
                'Entry 1 of the published address list holds a value under "ipv6Prefix" that is not text.',
            ],
            'an entry that is text' => [
                '{"prefixes": [{"ipv4Prefix": "192.0.2.0/24"}, "198.51.100.0/24"]}',
                'Entry 2 of the published address list is not an object.',
            ],
        ];
    }

    /**
     * A body that is not JSON is rejected with the decoder's failure as the cause
     *
     * @return void
     */
    public function testKeepsTheDecoderFailureAsTheCause(): void
    {
        try {
            self::parser()->parse('{not json');
        } catch (RangeListRejectedException $exception) {
            self::assertInstanceOf(\InvalidArgumentException::class, $exception->getPrevious());

            return;
        }

        self::fail('A body that is not JSON was not rejected.');
    }

    /**
     * The parser with the real JSON decoder
     *
     * @return PrefixListParser
     */
    private static function parser(): PrefixListParser
    {
        return new PrefixListParser(new Json());
    }
}
