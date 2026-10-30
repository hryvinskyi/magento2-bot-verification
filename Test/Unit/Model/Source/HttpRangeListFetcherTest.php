<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Source;

use Hryvinskyi\BotVerification\Api\PrefixListParserInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Source\HttpRangeListFetcher;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The fetcher bounds every request, accepts only a 200 answer of at most 2 MiB, and reads it with the parser of the
 * list's format; a format without a parser is rejected before any request.
 */
class HttpRangeListFetcherTest extends TestCase
{
    private const URL = 'https://example.com/crawler.json';
    private const FORMAT = 'example_format';
    private const LIMIT = 2097152;

    /**
     * A format with no registered parser, or registered with something that is not a parser, is rejected and no
     * client is ever created
     *
     * @dataProvider unusablePools
     * @param array<array-key, mixed> $parsers Parser pool as configured
     * @return void
     */
    public function testAFormatWithoutAParserIsRejectedBeforeAnyRequest(array $parsers): void
    {
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->expects(self::never())->method('create');

        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage(sprintf(
            'No parser is registered for the list format "%s" (%s); this is a configuration fault.',
            self::FORMAT,
            self::URL
        ));

        (new HttpRangeListFetcher($clientFactory, $parsers))->fetch(self::URL, self::FORMAT);
    }

    /**
     * Parser pools that hold no parser for the requested format
     *
     * @return array<string, array{array<array-key, mixed>}>
     */
    public static function unusablePools(): array
    {
        return [
            'no pool at all' => [[]],
            'a parser for another format only' => [['other_format' => new class implements PrefixListParserInterface {
                /**
                 * @inheritDoc
                 */
                public function parse(string $body): array
                {
                    return ['192.0.2.0/24'];
                }
            }]],
            'an entry that is not a parser' => [[self::FORMAT => 'not a parser']],
        ];
    }

    /**
     * The body of a 200 answer is read by the parser registered for the requested format, and only by that one
     *
     * @return void
     */
    public function testTheParserOfTheRequestedFormatReadsTheBody(): void
    {
        $other = $this->createMock(PrefixListParserInterface::class);
        $other->expects(self::never())->method('parse');
        $requested = $this->createMock(PrefixListParserInterface::class);
        $requested->expects(self::once())
            ->method('parse')
            ->with('the body')
            ->willReturn(['192.0.2.0/24', '2001:db8::/32']);

        $prefixes = (new HttpRangeListFetcher(
            $this->clientFactory($this->client(200, 'the body')),
            ['other_format' => $other, self::FORMAT => $requested]
        ))->fetch(self::URL, self::FORMAT);

        self::assertSame(['192.0.2.0/24', '2001:db8::/32'], $prefixes);
    }

    /**
     * A client is bounded before it sends the request: 15 seconds in all, 5 to connect, HTTPS only, no redirect
     * followed, a body of at most 2 MiB - all options in one call, since the client keeps only its last set
     *
     * @return void
     */
    public function testTheClientIsBoundedBeforeTheRequest(): void
    {
        $calls = [];
        $client = $this->createMock(ClientInterface::class);
        $client->expects(self::once())->method('setTimeout')->with(15)->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'setTimeout';
            }
        );
        $client->expects(self::once())->method('setOptions')->with([
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => self::LIMIT,
        ])->willReturnCallback(
            static function () use (&$calls): void {
                $calls[] = 'setOptions';
            }
        );
        $client->expects(self::never())->method('setOption');
        $client->expects(self::once())->method('get')->with(self::URL)->willReturnCallback(
            static function () use (&$calls): array {
                $calls[] = 'get';

                return [];
            }
        );
        $client->method('getStatus')->willReturn(200);
        $client->method('getBody')->willReturn('{}');

        $this->fetcher($client)->fetch(self::URL, self::FORMAT);

        self::assertSame(['setTimeout', 'setOptions', 'get'], $calls);
    }

    /**
     * Any status other than 200 rejects the list, a redirect included, and the body is never parsed
     *
     * @dataProvider statusesOtherThanOk
     * @param int $status Status the list's server answers with
     * @return void
     */
    public function testAnAnswerOtherThan200IsRejected(int $status): void
    {
        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage(sprintf(
            'The address list at %s answered with HTTP status %d instead of 200; redirects are not followed.',
            self::URL,
            $status
        ));

        $this->fetcher($this->client($status, '{"prefixes": []}'), $this->unusedParser())
            ->fetch(self::URL, self::FORMAT);
    }

    /**
     * Statuses the fetcher does not accept
     *
     * @return array<string, array{int}>
     */
    public static function statusesOtherThanOk(): array
    {
        return [
            'service unavailable' => [503],
            'moved permanently' => [301],
            'found' => [302],
            'not found' => [404],
            'no content' => [204],
        ];
    }

    /**
     * A transport failure rejects the list, with the transport's description and the failure as the cause
     *
     * @return void
     */
    public function testATransportFailureIsRejected(): void
    {
        $failure = new \Exception('Could not resolve host: example.com');
        $client = $this->createMock(ClientInterface::class);
        $client->method('get')->willThrowException($failure);
        $client->expects(self::never())->method('getBody');

        try {
            $this->fetcher($client, $this->unusedParser())->fetch(self::URL, self::FORMAT);
        } catch (RangeListRejectedException $exception) {
            self::assertSame(
                'The address list at ' . self::URL . ' could not be fetched: Could not resolve host: example.com',
                $exception->getMessage()
            );
            self::assertSame($failure, $exception->getPrevious());

            return;
        }

        self::fail('A transport failure was not rejected.');
    }

    /**
     * A body one byte over 2 MiB is rejected without being parsed
     *
     * @return void
     */
    public function testABodyOverTheLimitIsRejectedWithoutParsing(): void
    {
        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage(
            sprintf('The address list at %s is larger than %d bytes.', self::URL, self::LIMIT)
        );

        $this->fetcher($this->client(200, str_repeat('0', self::LIMIT + 1)), $this->unusedParser())
            ->fetch(self::URL, self::FORMAT);
    }

    /**
     * A body of exactly 2 MiB is parsed
     *
     * @return void
     */
    public function testABodyAtTheLimitIsParsed(): void
    {
        $parser = $this->createMock(PrefixListParserInterface::class);
        $parser->expects(self::once())->method('parse')->willReturn(['192.0.2.0/24']);

        $prefixes = $this->fetcher($this->client(200, str_repeat('0', self::LIMIT)), $parser)
            ->fetch(self::URL, self::FORMAT);

        self::assertSame(['192.0.2.0/24'], $prefixes);
    }

    /**
     * A parser's rejection reaches the caller unchanged
     *
     * @return void
     */
    public function testAParserRejectionIsPassedOn(): void
    {
        $rejection = new RangeListRejectedException(__('The published address list holds no prefixes.'));
        $parser = $this->createMock(PrefixListParserInterface::class);
        $parser->method('parse')->willThrowException($rejection);

        try {
            $this->fetcher($this->client(200, '{"prefixes": []}'), $parser)->fetch(self::URL, self::FORMAT);
        } catch (RangeListRejectedException $exception) {
            self::assertSame($rejection, $exception);

            return;
        }

        self::fail('The parser\'s rejection was not passed on.');
    }

    /**
     * The fetcher over a client, with one parser registered for the test's format
     *
     * @param ClientInterface&MockObject $client Client every fetch uses
     * @param PrefixListParserInterface|null $parser Parser of the format; a stub returning one prefix when null
     * @return HttpRangeListFetcher
     */
    private function fetcher(ClientInterface $client, ?PrefixListParserInterface $parser = null): HttpRangeListFetcher
    {
        if ($parser === null) {
            $parser = $this->createStub(PrefixListParserInterface::class);
            $parser->method('parse')->willReturn(['192.0.2.0/24']);
        }

        return new HttpRangeListFetcher($this->clientFactory($client), [self::FORMAT => $parser]);
    }

    /**
     * A factory that creates the given client, once
     *
     * @param ClientInterface&MockObject $client Client to create
     * @return ClientFactory&MockObject
     */
    private function clientFactory(ClientInterface $client): ClientFactory
    {
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->expects(self::once())->method('create')->willReturn($client);

        return $clientFactory;
    }

    /**
     * A client whose request answers with the given status and body
     *
     * @param int $status Status of the answer
     * @param string $body Body of the answer
     * @return ClientInterface&MockObject
     */
    private function client(int $status, string $body): ClientInterface
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getStatus')->willReturn($status);
        $client->method('getBody')->willReturn($body);

        return $client;
    }

    /**
     * A parser that must never be asked to read anything
     *
     * @return PrefixListParserInterface&MockObject
     */
    private function unusedParser(): PrefixListParserInterface
    {
        $parser = $this->createMock(PrefixListParserInterface::class);
        $parser->expects(self::never())->method('parse');

        return $parser;
    }
}
