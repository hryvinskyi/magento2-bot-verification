<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Plugin;

use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterfaceFactory;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Api\RequestVerifierInterface;
use Hryvinskyi\BotVerification\Model\RequestVerifier;
use Hryvinskyi\BotVerification\Plugin\RefuseImpostorPlugin;
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * The refusal is registered on the real front controller and its 403 survives a real storefront dispatch
 *
 * The verdict is not under test here: the request verifier the plugin asks for - through its lazy proxy, which
 * resolves the contract's implementation from the container - is replaced by one that gives every request the verdict
 * the case wants, an impostor's by default. The path is unique and does not exist: routed, it would be answered with a 404, so a 403 can only come
 * from the refusal.
 *
 * The container keeps the plugin it built for an earlier dispatch, and the lazy proxy of the verifier behind it, for
 * the rest of the process. Both are dropped before and after every case, so the plugin is rebuilt over the stub and
 * nothing built here outlives the case.
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class RefuseImpostorDispatchTest extends AbstractController
{
    /**
     * The server values as they were before the test; null before it starts
     *
     * @var array<mixed>|null
     */
    private ?array $server = null;

    /**
     * @inheritDoc
     */
    protected function setUp(): void
    {
        parent::setUp();
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        self::forgetBuiltInstances($objectManager);
        $objectManager->addSharedInstance(self::verifierJudging(VerdictStatus::Impostor), RequestVerifier::class);
        $this->server = $_SERVER;
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
    }

    /**
     * @inheritDoc
     */
    protected function tearDown(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        self::forgetBuiltInstances($objectManager);
        $_SERVER = $this->server ?? $_SERVER;
        parent::tearDown();
    }

    /**
     * The verdict of the request verifier in place when a request is dispatched decides that request, however many
     * requests the process dispatched before: a genuine crawler is routed, and the next request, once the verifier is
     * swapped for one that judges it an impostor, is refused
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testEachDispatchIsJudgedByTheVerifierInPlaceForIt(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);
        self::forgetBuiltInstances($objectManager);
        $objectManager->addSharedInstance(self::verifierJudging(VerdictStatus::Genuine), RequestVerifier::class);
        $this->dispatch('/bot-verification-refusal-' . bin2hex(random_bytes(8)));
        $response = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame(404, $response->getHttpResponseCode());

        self::forgetBuiltInstances($objectManager);
        $objectManager->addSharedInstance(self::verifierJudging(VerdictStatus::Impostor), RequestVerifier::class);
        $this->dispatch('/bot-verification-refusal-' . bin2hex(random_bytes(8)));

        $refused = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $refused);
        self::assertSame(403, $refused->getHttpResponseCode());
        self::assertSame('Forbidden', $refused->getBody());
    }

    /**
     * With the switch off the same impostor is routed like any visitor: the unknown path ends in the storefront's own
     * 404, not in a refusal
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 0
     * @return void
     */
    public function testAnImpostorIsRoutedWhileRefusalIsOff(): void
    {
        $this->dispatch('/bot-verification-refusal-' . bin2hex(random_bytes(8)));

        $response = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame(404, $response->getHttpResponseCode());
        self::assertNotSame('Forbidden', $response->getBody());
    }

    /**
     * An impostor's storefront request is answered with a private, uncacheable 403 before it is routed
     *
     * @magentoConfigFixture current_store hryvinskyi_bot_verification/impostor/refuse 1
     * @return void
     */
    public function testAnImpostorIsRefusedBeforeRouting(): void
    {
        $this->dispatch('/bot-verification-refusal-' . bin2hex(random_bytes(8)));

        $response = $this->getResponse();
        self::assertInstanceOf(HttpResponse::class, $response);
        self::assertSame(403, $response->getHttpResponseCode());
        $cacheControl = $response->getHeader('Cache-Control');
        self::assertInstanceOf(HeaderInterface::class, $cacheControl);
        // The header object puts the directives in its own order.
        self::assertEqualsCanonicalizing(
            ['private', 'no-store', 'no-cache', 'must-revalidate', 'max-age=0'],
            explode(', ', $cacheControl->getFieldValue())
        );
        self::assertSame('Forbidden', $response->getBody());
    }

    /**
     * Drop the shared instances that remember the request verifier they were built with: the verifier itself, the
     * lazy proxy the plugin holds, the plugin, and the front controller, whose generated interceptor keeps the plugin
     * instances it has called; the container rebuilds all of them on their next use
     *
     * @param ObjectManager $objectManager The container of the test run
     * @return void
     */
    private static function forgetBuiltInstances(ObjectManager $objectManager): void
    {
        foreach ([
            RequestVerifier::class,
            RequestVerifierInterface::class . '\\Proxy',
            RefuseImpostorPlugin::class,
        ] as $type) {
            $objectManager->removeSharedInstance($type);
        }
        $objectManager->removeSharedInstance(FrontControllerInterface::class, true);
    }

    /**
     * A request verifier that gives every request the same verdict about a neutral crawler, built by the container's
     * factory
     *
     * @param VerdictStatus $status The verdict on every request
     * @return RequestVerifierInterface
     */
    private static function verifierJudging(VerdictStatus $status): RequestVerifierInterface
    {
        $factory = Bootstrap::getObjectManager()->get(VerdictInterfaceFactory::class);
        self::assertInstanceOf(VerdictInterfaceFactory::class, $factory);
        $verdict = $factory->create(['status' => $status, 'crawlerCode' => 'example']);

        return new class ($verdict) implements RequestVerifierInterface {
            /**
             * @param VerdictInterface $verdict The verdict on every request
             */
            public function __construct(
                private readonly VerdictInterface $verdict
            ) {
            }

            /**
             * @inheritDoc
             */
            public function verify(): VerdictInterface
            {
                return $this->verdict;
            }
        };
    }
}
