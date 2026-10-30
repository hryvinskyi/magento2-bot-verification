<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Address;

use Hryvinskyi\BotVerification\Model\Address\ProxyChainClientAddressResolver;
use Hryvinskyi\BotVerification\Test\Double\RecordingForwardingChainResolver;
use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Request\Http;
use Magento\Framework\App\Request\PathInfo;
use Magento\Framework\App\Request\PathInfoProcessorInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Route\ConfigInterface;
use Magento\Framework\ObjectManagerInterface;
use Magento\Framework\Stdlib\Cookie\CookieReaderInterface;
use Magento\Framework\Stdlib\StringUtils;
use PHPUnit\Framework\TestCase;

/**
 * The request adapter reads the connecting address and X-Forwarded-For of an HTTP request and hands them to the
 * forwarding-chain walk unchanged; without a connecting address, or for a request that is not an HTTP request, there
 * is no client address and the walk is not asked.
 */
class ProxyChainClientAddressResolverTest extends TestCase
{
    /**
     * Both values reach the walk as the request carries them, and its answer is the client address
     *
     * @return void
     */
    public function testHandsBothValuesToTheWalk(): void
    {
        $chainResolver = new RecordingForwardingChainResolver('198.51.100.7');
        $request = $this->request(['REMOTE_ADDR' => '127.0.0.1', 'HTTP_X_FORWARDED_FOR' => '198.51.100.7, 10.0.0.5']);

        $client = (new ProxyChainClientAddressResolver($request, $chainResolver))->resolve();

        self::assertSame('198.51.100.7', $client);
        self::assertSame(
            [['remoteAddress' => '127.0.0.1', 'forwardedFor' => '198.51.100.7, 10.0.0.5']],
            $chainResolver->calls
        );
    }

    /**
     * A request without X-Forwarded-For reaches the walk with an empty header
     *
     * @return void
     */
    public function testAMissingHeaderIsEmpty(): void
    {
        $chainResolver = new RecordingForwardingChainResolver('198.51.100.7');

        (new ProxyChainClientAddressResolver($this->request(['REMOTE_ADDR' => '198.51.100.7']), $chainResolver))
            ->resolve();

        self::assertSame([['remoteAddress' => '198.51.100.7', 'forwardedFor' => '']], $chainResolver->calls);
    }

    /**
     * The walk's answer that no client can be determined is passed on
     *
     * @return void
     */
    public function testPassesOnAnUndeterminedClient(): void
    {
        $request = $this->request(['REMOTE_ADDR' => '127.0.0.1']);

        self::assertNull(
            (new ProxyChainClientAddressResolver($request, new RecordingForwardingChainResolver(null)))->resolve()
        );
    }

    /**
     * Without a connecting address there is no client address, and the walk is not asked
     *
     * @return void
     */
    public function testAMissingConnectingAddressIsNoClient(): void
    {
        $chainResolver = new RecordingForwardingChainResolver('198.51.100.7');
        $request = $this->request(['HTTP_X_FORWARDED_FOR' => '198.51.100.7']);

        self::assertNull((new ProxyChainClientAddressResolver($request, $chainResolver))->resolve());
        self::assertSame([], $chainResolver->calls);
    }

    /**
     * A request that is not an HTTP request has no client address, and the walk is not asked
     *
     * @return void
     */
    public function testARequestThatIsNotAnHttpRequestIsNoClient(): void
    {
        $chainResolver = new RecordingForwardingChainResolver('198.51.100.7');

        $client = (new ProxyChainClientAddressResolver($this->createMock(RequestInterface::class), $chainResolver))
            ->resolve();

        self::assertNull($client);
        self::assertSame([], $chainResolver->calls);
    }

    /**
     * An HTTP request, as every area receives it, whose server values are exactly the given ones
     *
     * @param array<string, string> $server Server values
     * @return Http
     */
    private function request(array $server): Http
    {
        $request = new Http(
            $this->createMock(CookieReaderInterface::class),
            $this->createMock(StringUtils::class),
            $this->createMock(ConfigInterface::class),
            $this->createMock(PathInfoProcessorInterface::class),
            $this->createMock(ObjectManagerInterface::class),
            null,
            [],
            $this->createMock(PathInfo::class)
        );
        $request->setServer(new Parameters($server));

        return $request;
    }
}
