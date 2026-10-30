<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Source;

use Hryvinskyi\BotVerification\Api\RangeListFetcherInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Model\Source\HttpRangeListFetcher;
use Magento\Framework\HTTP\ClientFactory;
use Magento\Framework\HTTP\ClientInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The fetcher as the container builds it, with the parsers this module registers, over a client that answers in
 * place of the network.
 */
class HttpRangeListFetcherTest extends TestCase
{
    private const URL = 'https://example.com/list';

    /**
     * The fetcher contract is filled by the HTTP fetcher
     *
     * @return void
     */
    public function testTheContainerServesTheHttpFetcher(): void
    {
        self::assertInstanceOf(
            HttpRangeListFetcher::class,
            Bootstrap::getObjectManager()->get(RangeListFetcherInterface::class)
        );
    }

    /**
     * Each format this module registers is read by its configured parser
     *
     * @dataProvider documents
     * @param string $format Code of the list's format
     * @param string $body Document as published
     * @return void
     */
    public function testTheConfiguredParsersReadTheirFormats(string $format, string $body): void
    {
        $client = $this->createMock(ClientInterface::class);
        $client->method('getStatus')->willReturn(200);
        $client->method('getBody')->willReturn($body);
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->expects(self::once())->method('create')->willReturn($client);

        $prefixes = self::fetcher($clientFactory)->fetch(self::URL, $format);

        self::assertSame(['192.0.2.0/24', '2001:db8:4801::/64'], $prefixes);
    }

    /**
     * The registered formats and a document in each
     *
     * @return array<string, array{string, string}>
     */
    public static function documents(): array
    {
        return [
            'the prefixes document' => [
                'prefix_json',
                '{"creationTime": "2026-10-30T06:00:00", "prefixes": [{"ipv4Prefix": "192.0.2.0/24"}, '
                . '{"ipv6Prefix": "2001:db8:4801::/64"}]}',
            ],
            'one prefix per line' => ['plain_text', "192.0.2.0/24\r\n2001:db8:4801::/64\r\n"],
        ];
    }

    /**
     * A format nobody registered is rejected before a client is created
     *
     * @return void
     */
    public function testAnUnregisteredFormatIsRejectedBeforeAnyRequest(): void
    {
        $clientFactory = $this->createMock(ClientFactory::class);
        $clientFactory->expects(self::never())->method('create');

        $this->expectException(RangeListRejectedException::class);
        $this->expectExceptionMessage('No parser is registered for the list format "example_unregistered"');

        self::fetcher($clientFactory)->fetch(self::URL, 'example_unregistered');
    }

    /**
     * A fresh fetcher as the container builds it, over the given client factory
     *
     * @param ClientFactory $clientFactory Creates the client of each fetch
     * @return HttpRangeListFetcher
     */
    private static function fetcher(ClientFactory $clientFactory): HttpRangeListFetcher
    {
        $fetcher = Bootstrap::getObjectManager()->create(
            HttpRangeListFetcher::class,
            ['clientFactory' => $clientFactory]
        );
        self::assertInstanceOf(HttpRangeListFetcher::class, $fetcher);

        return $fetcher;
    }
}
