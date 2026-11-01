<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Plugin;

use Hryvinskyi\BotVerification\Api\ConfigInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Model\Address\ForwardingChainResolver;
use Hryvinskyi\BotVerification\Model\Address\ProxyChainClientAddressResolver;
use Hryvinskyi\BotVerification\Model\Address\TrustedProxyRanges;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Crawler\UserAgentClaimMatcher;
use Hryvinskyi\BotVerification\Model\CrawlerVerifier;
use Hryvinskyi\BotVerification\Model\RequestVerifier;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Plugin\RefuseImpostorPlugin;
use Laminas\Http\Header\HeaderInterface;
use Laminas\Stdlib\Parameters;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\HTTP\Header;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

/**
 * The refusal over the container's configuration, settings, address parser, freshness and verdict factory, and the
 * real snapshot storage, judging requests built here
 *
 * Every dispatch gets objects of its own - request, header, registry, verifiers, resolver, response and plugin - so
 * no case can see what an earlier one memoised. The registry is neutral and built with all of its arguments, never
 * the container's: one crawler whose addresses are in two lists, and one edge-server list. The proxy lists that other
 * enabled modules declare, which no test here seeds, would otherwise leave every address after the shop's own
 * proxies undecided. The shop's own proxies are the shipped default ranges, loopback and private space included.
 *
 * The storefront area is loaded because the front controller handed to the plugin exists only in an area that
 * routes requests.
 *
 * @magentoAppArea frontend
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class RefuseImpostorPluginTest extends TestCase
{
    private const CRAWLER_UA = 'Mozilla/5.0 (compatible; ExampleBot/1.0; +http://bot.example/)';
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';
    private const LIST_A = 'example_list';
    private const LIST_B = 'example_list_b';
    private const EDGE_LIST = 'example_edge';
    private const IMPOSTOR = '203.0.113.9';
    private const GENUINE = '198.51.100.7';
    private const GENUINE_IN_LIST_B = '198.51.100.200';
    private const EDGE_SERVER = '192.0.2.10';
    private const DAY = 86400;

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
     * An impostor gets the injected response as a private, uncacheable plain-text 403, and is never dispatched
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAnImpostorIsRefused(): void
    {
        self::seedFresh();

        $outcome = self::judge(self::CRAWLER_UA, self::IMPOSTOR);

        self::assertRefused($outcome);
        $cacheControl = $outcome['response']->getHeader('Cache-Control');
        self::assertInstanceOf(HeaderInterface::class, $cacheControl);
        // The header object puts the directives in its own order.
        self::assertEqualsCanonicalizing(
            ['private', 'no-store', 'no-cache', 'must-revalidate', 'max-age=0'],
            explode(', ', $cacheControl->getFieldValue())
        );
        self::assertSame('Forbidden', $outcome['response']->getBody());
    }

    /**
     * A crawler at an address in either of its lists is dispatched
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAGenuineCrawlerIsDispatched(): void
    {
        self::seedFresh();

        self::assertDispatched(self::judge(self::CRAWLER_UA, self::GENUINE));
        self::assertDispatched(self::judge(self::CRAWLER_UA, self::GENUINE_IN_LIST_B));
    }

    /**
     * A request that claims no crawler is dispatched wherever it comes from
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testABrowserIsDispatched(): void
    {
        self::seedFresh();

        self::assertDispatched(self::judge(self::BROWSER_UA, self::IMPOSTOR));
    }

    /**
     * A request from one of the shop's own proxies with nothing behind it has no visitor address to judge
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAnOwnProxyWithNothingBehindItIsDispatched(): void
    {
        self::seedFresh();

        self::assertDispatched(self::judge(self::CRAWLER_UA, '10.0.0.1'));
    }

    /**
     * Behind the shop's own proxy the address it forwarded is judged
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testTheAddressBehindAnOwnProxyIsJudged(): void
    {
        self::seedFresh();

        self::assertRefused(self::judge(self::CRAWLER_UA, '127.0.0.1', self::IMPOSTOR));
        self::assertDispatched(self::judge(self::CRAWLER_UA, '127.0.0.1', self::GENUINE));
    }

    /**
     * A forwarding header is not read when the connecting address is not a trusted proxy
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAForgedForwardingHeaderIsNotRead(): void
    {
        self::seedFresh();

        self::assertRefused(self::judge(self::CRAWLER_UA, self::IMPOSTOR, self::GENUINE));
    }

    /**
     * Behind a stored edge server the address it appended is judged; an edge-list address left of it is the client
     * itself, not a second proxy
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testTheAddressBehindOneEdgeServerIsJudged(): void
    {
        self::seedFresh();
        $edgeChain = ', ' . self::EDGE_SERVER . ', 127.0.0.1';

        self::assertRefused(self::judge(self::CRAWLER_UA, '127.0.0.1', self::IMPOSTOR . $edgeChain));
        self::assertDispatched(self::judge(self::CRAWLER_UA, '127.0.0.1', self::GENUINE . $edgeChain));
        self::assertRefused(
            self::judge(self::CRAWLER_UA, '127.0.0.1', self::GENUINE . ', 192.0.2.20' . $edgeChain)
        );
    }

    /**
     * While the edge list has never been stored, an edge server cannot be told from a visitor and nobody is refused
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAnUnstoredEdgeListRefusesNobody(): void
    {
        self::seed(self::LIST_A, '198.51.100.0/25', time());
        self::seed(self::LIST_B, '198.51.100.128/25', time());

        self::assertDispatched(
            self::judge(self::CRAWLER_UA, '127.0.0.1', self::IMPOSTOR . ', ' . self::EDGE_SERVER . ', 127.0.0.1')
        );
    }

    /**
     * Lists older than the maximum age accuse nobody
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testStaleListsRefuseNobody(): void
    {
        self::seed(self::LIST_A, '198.51.100.0/25', time() - 8 * self::DAY);
        self::seed(self::LIST_B, '198.51.100.128/25', time() - 8 * self::DAY);
        self::seed(self::EDGE_LIST, '192.0.2.0/24', time());

        self::assertDispatched(self::judge(self::CRAWLER_UA, self::IMPOSTOR));
    }

    /**
     * One list of the crawler never stored, the other fresh: the address may be in the missing one
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAMissingListRefusesNobody(): void
    {
        self::seed(self::LIST_A, '198.51.100.0/25', time());
        self::seed(self::EDGE_LIST, '192.0.2.0/24', time());

        self::assertDispatched(self::judge(self::CRAWLER_UA, self::IMPOSTOR));
    }

    /**
     * With the switch off an impostor is dispatched
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 0
     * @return void
     */
    public function testRefusalOffRefusesNobody(): void
    {
        self::seedFresh();

        self::assertDispatched(self::judge(self::CRAWLER_UA, self::IMPOSTOR));
    }

    /**
     * A switch that cannot be read - as when the store is resolved this early and fails - dispatches an impostor
     *
     * @return void
     */
    public function testAnUnreadableSwitchRefusesNobody(): void
    {
        self::seedFresh();
        $config = $this->createMock(ConfigInterface::class);
        $config->method('isImpostorRefusalEnabled')
            ->willThrowException(new \RuntimeException('Default store is inactive'));

        self::assertDispatched(self::judge(self::CRAWLER_UA, self::IMPOSTOR, '', $config));
    }

    /**
     * The request was refused: the plugin returned its own response with a 403 and never called the rest of the chain
     *
     * @param array{result: ResponseInterface|ResultInterface, proceeded: bool, response: HttpResponse} $outcome
     * @return void
     */
    private static function assertRefused(array $outcome): void
    {
        self::assertFalse($outcome['proceeded'], 'The request was dispatched.');
        self::assertSame($outcome['response'], $outcome['result']);
        self::assertSame(403, $outcome['response']->getHttpResponseCode());
    }

    /**
     * The request was dispatched: the rest of the chain ran and its result was returned, and the plugin's response
     * was left alone
     *
     * @param array{result: ResponseInterface|ResultInterface, proceeded: bool, response: HttpResponse} $outcome
     * @return void
     */
    private static function assertDispatched(array $outcome): void
    {
        self::assertTrue($outcome['proceeded'], 'The request was refused.');
        self::assertNotSame($outcome['response'], $outcome['result']);
        self::assertSame(200, $outcome['response']->getHttpResponseCode());
    }

    /**
     * Dispatch a fresh request through a fresh plugin and record what happened
     *
     * @param string $userAgent User-Agent header
     * @param string $remoteAddress Connecting address
     * @param string $forwardedFor X-Forwarded-For header; '' for none
     * @param ConfigInterface|null $config Settings to give the plugin instead of the container's
     * @return array{result: ResponseInterface|ResultInterface, proceeded: bool, response: HttpResponse}
     */
    private static function judge(
        string $userAgent,
        string $remoteAddress,
        string $forwardedFor = '',
        ?ConfigInterface $config = null
    ): array {
        $server = ['HTTP_USER_AGENT' => $userAgent, 'REMOTE_ADDR' => $remoteAddress];
        if ($forwardedFor !== '') {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        $request = self::create(Request::class);
        $request->setServer(new Parameters($server));
        $response = self::create(HttpResponse::class);
        $dispatched = self::create(HttpResponse::class);

        $proceeded = false;
        $result = self::plugin($request, $response, $config)->aroundDispatch(
            self::frontController(),
            static function (RequestInterface $passed) use (&$proceeded, $request, $dispatched): HttpResponse {
                self::assertSame($request, $passed);
                $proceeded = true;

                return $dispatched;
            },
            $request
        );

        return ['result' => $result, 'proceeded' => $proceeded, 'response' => $response];
    }

    /**
     * A plugin over the request, with every object between them created for this request alone
     *
     * @param Request $request The request to judge
     * @param HttpResponse $response The response a refusal fills
     * @param ConfigInterface|null $config Settings to give the plugin instead of the container's
     * @return RefuseImpostorPlugin
     */
    private static function plugin(
        Request $request,
        HttpResponse $response,
        ?ConfigInterface $config = null
    ): RefuseImpostorPlugin {
        $registry = self::registry();
        $claimMatcher = self::create(UserAgentClaimMatcher::class, ['registry' => $registry]);
        $trustedProxies = self::create(TrustedProxyRanges::class, [
            'registry' => $registry,
            'snapshots' => self::freshRepository(),
        ]);
        $clientAddressResolver = self::create(ProxyChainClientAddressResolver::class, [
            'request' => $request,
            'chainResolver' => self::create(ForwardingChainResolver::class, ['trustedProxies' => $trustedProxies]),
        ]);
        $requestVerifier = self::create(RequestVerifier::class, [
            'header' => self::create(Header::class, ['httpRequest' => $request]),
            'claimMatcher' => $claimMatcher,
            'clientAddressResolver' => $clientAddressResolver,
            'crawlerVerifier' => self::create(CrawlerVerifier::class, [
                'registry' => $registry,
                'claimMatcher' => $claimMatcher,
                'snapshots' => self::freshRepository(),
            ]),
        ]);

        $arguments = ['requestVerifier' => $requestVerifier, 'response' => $response];
        if ($config !== null) {
            $arguments['config'] = $config;
        }

        return self::create(RefuseImpostorPlugin::class, $arguments);
    }

    /**
     * The neutral registry: one crawler whose addresses are in two lists, and one edge-server list in plain text
     *
     * Every argument is passed, empty ones included, so nothing that enabled modules declare leaks in.
     *
     * @return CrawlerRegistry
     */
    private static function registry(): CrawlerRegistry
    {
        return self::create(CrawlerRegistry::class, [
            'crawlers' => [
                'example' => ['tokens' => ['examplebot' => 'ExampleBot'], 'lists' => [self::LIST_A, self::LIST_B]],
            ],
            'rangeLists' => [
                self::LIST_A => 'https://bot.example/a.json',
                self::LIST_B => 'https://bot.example/b.json',
                self::EDGE_LIST => 'https://edge.example/ranges.txt',
            ],
            'rangeListFormats' => [self::EDGE_LIST => 'plain_text'],
            'proxyLists' => [self::EDGE_LIST],
        ]);
    }

    /**
     * Store every list fresh: the crawler's two halves of one range, and the edge servers' range
     *
     * @return void
     */
    private static function seedFresh(): void
    {
        self::seed(self::LIST_A, '198.51.100.0/25', time());
        self::seed(self::LIST_B, '198.51.100.128/25', time());
        self::seed(self::EDGE_LIST, '192.0.2.0/24', time());
    }

    /**
     * Store a snapshot of one range, built by the container's builder, through a fresh repository
     *
     * @param string $listCode Code of the list
     * @param string $range CIDR range the list holds
     * @param int $fetchedAt Unix time the list counts as fetched at
     * @return void
     */
    private static function seed(string $listCode, string $range, int $fetchedAt): void
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);
        self::freshRepository()->save($listCode, $builder->build([$range], $fetchedAt));
    }

    /**
     * A fresh snapshot repository as the container builds it, with its configured durable storage and cache
     *
     * @return CachedRangeSnapshotRepository
     */
    private static function freshRepository(): CachedRangeSnapshotRepository
    {
        return self::create(CachedRangeSnapshotRepository::class);
    }

    /**
     * The container's front controller, which the plugin is given and does not use
     *
     * @return FrontControllerInterface
     */
    private static function frontController(): FrontControllerInterface
    {
        $frontController = Bootstrap::getObjectManager()->get(FrontControllerInterface::class);
        self::assertInstanceOf(FrontControllerInterface::class, $frontController);

        return $frontController;
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

    /**
     * A new instance of a type, created by the container
     *
     * @template T of object
     * @param class-string<T> $type
     * @param array<string, mixed> $arguments Constructor arguments to pass instead of the configured ones
     * @return T
     */
    private static function create(string $type, array $arguments = []): object
    {
        $instance = Bootstrap::getObjectManager()->create($type, $arguments);
        self::assertInstanceOf($type, $instance);

        return $instance;
    }
}
