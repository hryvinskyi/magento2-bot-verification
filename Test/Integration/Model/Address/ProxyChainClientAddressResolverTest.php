<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Address;

use Hryvinskyi\BotVerification\Api\ClientAddressResolverInterface;
use Hryvinskyi\BotVerification\Api\ForwardingChainResolverInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Api\TrustedProxyRangesInterface;
use Hryvinskyi\BotVerification\Model\Address\ForwardingChainResolver;
use Hryvinskyi\BotVerification\Model\Address\ProxyChainClientAddressResolver;
use Hryvinskyi\BotVerification\Model\Address\TrustedProxyRanges;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Cache\StateInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

/**
 * The client address of a request as the container builds the resolver: the shipped trusted-proxy configuration, the
 * real address parser, and proxy lists read from the real snapshot storage
 *
 * Proxy membership is decided over a neutral registry built here with all of its arguments, never the container's,
 * so the proxy lists that other enabled modules declare - and no test of this module seeds - do not leak in.
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class ProxyChainClientAddressResolverTest extends TestCase
{
    private const EDGE_LIST = 'example_edge';
    private const VISITOR = '198.51.100.7';

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        $state = Bootstrap::getObjectManager()->get(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);
        self::assertTrue(
            $state->isEnabled(RangeSnapshotCache::TYPE_IDENTIFIER),
            'The cache type is off, so nothing below could be read from the cache.'
        );
        self::cache()->clean();
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        self::cache()->clean();
    }

    /**
     * The container fills the address contracts with the walk, the request adapter and the trusted proxies
     *
     * @return void
     */
    public function testTheContainerFillsTheContracts(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        self::assertInstanceOf(
            ProxyChainClientAddressResolver::class,
            $objectManager->get(ClientAddressResolverInterface::class)
        );
        self::assertInstanceOf(
            ForwardingChainResolver::class,
            $objectManager->get(ForwardingChainResolverInterface::class)
        );
        self::assertInstanceOf(TrustedProxyRanges::class, $objectManager->get(TrustedProxyRangesInterface::class));
    }

    /**
     * With the shipped configuration and no proxy list, a loopback proxy is passed and a forged header behind no
     * proxy is never read
     *
     * @return void
     */
    public function testTheShippedConfigurationTrustsALoopbackProxy(): void
    {
        $registry = self::registry([]);

        self::assertSame(self::VISITOR, self::resolver('127.0.0.1', self::VISITOR, $registry)->resolve());
        self::assertSame(self::VISITOR, self::resolver(self::VISITOR, '203.0.113.9', $registry)->resolve());
    }

    /**
     * Behind a declared edge server whose list is stored, the entry the edge server appended is the client, and a
     * second edge-list address left of it is the client itself
     *
     * @return void
     */
    public function testPassesOneStoredEdgeServer(): void
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);
        self::freshRepository()->save(self::EDGE_LIST, $builder->build(['192.0.2.0/24'], time()));
        $registry = self::registry([self::EDGE_LIST]);

        $behindEdge = self::resolver(
            '127.0.0.1',
            '203.0.113.66, ' . self::VISITOR . ', 192.0.2.10, 127.0.0.1',
            $registry
        );
        $fromInsideTheEdgeNetwork = self::resolver(
            '127.0.0.1',
            self::VISITOR . ', 192.0.2.20, 192.0.2.10, 127.0.0.1',
            $registry
        );

        self::assertSame(self::VISITOR, $behindEdge->resolve());
        self::assertSame('192.0.2.20', $fromInsideTheEdgeNetwork->resolve());
    }

    /**
     * A declared edge list that was never stored leaves the address after the own proxies undecided, and no client
     *
     * @return void
     */
    public function testAnUnstoredEdgeListLeavesNoClient(): void
    {
        $resolver = self::resolver(
            '127.0.0.1',
            '203.0.113.66, ' . self::VISITOR . ', 192.0.2.10, 127.0.0.1',
            self::registry([self::EDGE_LIST])
        );

        self::assertNull($resolver->resolve());
    }

    /**
     * A resolver as the container builds it, over a fresh request carrying the two values, the container's settings,
     * the given registry and a fresh snapshot repository
     *
     * @param string $remoteAddress Connecting address
     * @param string $forwardedFor X-Forwarded-For header
     * @param CrawlerRegistry $registry Declares the proxy lists
     * @return ProxyChainClientAddressResolver
     */
    private static function resolver(
        string $remoteAddress,
        string $forwardedFor,
        CrawlerRegistry $registry
    ): ProxyChainClientAddressResolver {
        $objectManager = Bootstrap::getObjectManager();
        $request = $objectManager->create(Request::class);
        self::assertInstanceOf(Request::class, $request);
        $request->setServer(new Parameters([
            'REMOTE_ADDR' => $remoteAddress,
            'HTTP_X_FORWARDED_FOR' => $forwardedFor,
        ]));
        $trustedProxies = $objectManager->create(TrustedProxyRanges::class, [
            'registry' => $registry,
            'snapshots' => self::freshRepository(),
        ]);
        $chainResolver = $objectManager->create(ForwardingChainResolver::class, ['trustedProxies' => $trustedProxies]);
        $resolver = $objectManager->create(ProxyChainClientAddressResolver::class, [
            'request' => $request,
            'chainResolver' => $chainResolver,
        ]);
        self::assertInstanceOf(ProxyChainClientAddressResolver::class, $resolver);

        return $resolver;
    }

    /**
     * A neutral registry: no crawler, and the given proxy lists in plain text
     *
     * @param list<string> $proxyLists Codes of the proxy lists
     * @return CrawlerRegistry
     */
    private static function registry(array $proxyLists): CrawlerRegistry
    {
        $urls = [];
        $formats = [];
        foreach ($proxyLists as $listCode) {
            $urls[$listCode] = 'https://edge.example/' . $listCode . '.txt';
            $formats[$listCode] = 'plain_text';
        }
        $registry = Bootstrap::getObjectManager()->create(CrawlerRegistry::class, [
            'crawlers' => [],
            'rangeLists' => $urls,
            'rangeListFormats' => $formats,
            'proxyLists' => $proxyLists,
        ]);
        self::assertInstanceOf(CrawlerRegistry::class, $registry);

        return $registry;
    }

    /**
     * A fresh snapshot repository as the container builds it, with its configured durable storage and cache
     *
     * @return CachedRangeSnapshotRepository
     */
    private static function freshRepository(): CachedRangeSnapshotRepository
    {
        $repository = Bootstrap::getObjectManager()->create(CachedRangeSnapshotRepository::class);
        self::assertInstanceOf(CachedRangeSnapshotRepository::class, $repository);

        return $repository;
    }

    /**
     * The module's snapshot cache
     *
     * @return RangeSnapshotCache
     */
    private static function cache(): RangeSnapshotCache
    {
        $cache = Bootstrap::getObjectManager()->get(RangeSnapshotCache::class);
        self::assertInstanceOf(RangeSnapshotCache::class, $cache);

        return $cache;
    }
}
