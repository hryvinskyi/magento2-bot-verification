<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model;

use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Crawler\UserAgentClaimMatcher;
use Hryvinskyi\BotVerification\Model\RequestVerifier;
use Hryvinskyi\BotVerification\Test\Double\CountingClientAddressResolver;
use Hryvinskyi\BotVerification\Test\Double\RecordingCrawlerVerifier;
use Magento\Framework\HTTP\Header;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * The request is judged on its raw User-Agent and, only when that claims a crawler, on its resolved client address;
 * the verdict is kept for the same User-Agent and address, and computed again for others.
 */
class RequestVerifierTest extends TestCase
{
    private const CLAIMING_UA = 'Mozilla/5.0 (compatible; ExampleBot/2.1; +http://bot.example/)';
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';
    private const CLIENT_IP = '203.0.113.7';

    /**
     * User-Agent the request header reports; tests change it between verifications
     */
    private string $userAgent = self::CLAIMING_UA;

    /**
     * A claiming User-Agent is judged with the resolved client address
     *
     * @return void
     */
    public function testPassesTheResolvedAddressOfAClaimingRequest(): void
    {
        $resolver = new CountingClientAddressResolver(self::CLIENT_IP);
        $crawlerVerifier = new RecordingCrawlerVerifier();

        $this->requestVerifier($resolver, $crawlerVerifier)->verify();

        self::assertSame(1, $resolver->calls);
        self::assertSame([['userAgent' => self::CLAIMING_UA, 'clientIp' => self::CLIENT_IP]], $crawlerVerifier->calls);
    }

    /**
     * An address that cannot be determined is passed on as no address
     *
     * @return void
     */
    public function testPassesNoAddressWhenItCannotBeDetermined(): void
    {
        $crawlerVerifier = new RecordingCrawlerVerifier();

        $this->requestVerifier(new CountingClientAddressResolver(null), $crawlerVerifier)->verify();

        self::assertSame([['userAgent' => self::CLAIMING_UA, 'clientIp' => '']], $crawlerVerifier->calls);
    }

    /**
     * A User-Agent that claims no crawler never has its address resolved
     *
     * @return void
     */
    public function testNeverResolvesTheAddressOfARequestThatClaimsNothing(): void
    {
        $this->userAgent = self::BROWSER_UA;
        $resolver = new CountingClientAddressResolver(self::CLIENT_IP);
        $crawlerVerifier = new RecordingCrawlerVerifier();

        $this->requestVerifier($resolver, $crawlerVerifier)->verify();

        self::assertSame(0, $resolver->calls);
        self::assertSame([['userAgent' => self::BROWSER_UA, 'clientIp' => '']], $crawlerVerifier->calls);
    }

    /**
     * Asking again with the same User-Agent and address serves the kept verdict
     *
     * @return void
     */
    public function testKeepsTheVerdictForTheSameInputs(): void
    {
        $crawlerVerifier = new RecordingCrawlerVerifier();
        $requestVerifier = $this->requestVerifier(new CountingClientAddressResolver(self::CLIENT_IP), $crawlerVerifier);

        $first = $requestVerifier->verify();
        $second = $requestVerifier->verify();

        self::assertSame($first, $second);
        self::assertCount(1, $crawlerVerifier->calls);
    }

    /**
     * A changed User-Agent or a changed address is judged again
     *
     * @return void
     */
    public function testJudgesChangedInputsAgain(): void
    {
        $resolver = new CountingClientAddressResolver(self::CLIENT_IP);
        $crawlerVerifier = new RecordingCrawlerVerifier();
        $requestVerifier = $this->requestVerifier($resolver, $crawlerVerifier);

        $first = $requestVerifier->verify();
        $resolver->address = '203.0.113.8';
        $second = $requestVerifier->verify();
        $this->userAgent = 'OtherBot/1.0';
        $third = $requestVerifier->verify();
        $this->userAgent = self::BROWSER_UA;
        $fourth = $requestVerifier->verify();

        self::assertNotSame($first, $second);
        self::assertNotSame($second, $third);
        self::assertNotSame($third, $fourth);
        self::assertSame(
            [
                ['userAgent' => self::CLAIMING_UA, 'clientIp' => self::CLIENT_IP],
                ['userAgent' => self::CLAIMING_UA, 'clientIp' => '203.0.113.8'],
                ['userAgent' => 'OtherBot/1.0', 'clientIp' => '203.0.113.8'],
                ['userAgent' => self::BROWSER_UA, 'clientIp' => ''],
            ],
            $crawlerVerifier->calls
        );
    }

    /**
     * The request verifier over a header reporting the current User-Agent raw, and a neutral claim matcher
     *
     * @param CountingClientAddressResolver $resolver Client address of the request
     * @param RecordingCrawlerVerifier $crawlerVerifier Records what it is asked to judge
     * @return RequestVerifier
     */
    private function requestVerifier(
        CountingClientAddressResolver $resolver,
        RecordingCrawlerVerifier $crawlerVerifier
    ): RequestVerifier {
        /** @var Header&MockObject $header */
        $header = $this->createMock(Header::class);
        $header->method('getHttpUserAgent')->with(false)->willReturnCallback(fn (): string => $this->userAgent);

        $registry = new CrawlerRegistry(
            [
                'example' => ['tokens' => ['ExampleBot'], 'lists' => ['example_list']],
                'other' => ['tokens' => ['OtherBot'], 'lists' => ['other_list']],
            ],
            [
                'example_list' => 'https://bot.example/example.json',
                'other_list' => 'https://bot.example/other.json',
            ]
        );

        return new RequestVerifier($header, new UserAgentClaimMatcher($registry), $resolver, $crawlerVerifier);
    }
}
