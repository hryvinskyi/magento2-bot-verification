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
use Laminas\Http\Header\HeaderInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use Magento\TestFramework\TestCase\AbstractController;

/**
 * The refusal is registered on the real front controller and its 403 survives a real storefront dispatch
 *
 * The verdict is not under test here: the request verifier the plugin asks for - through its lazy proxy, which
 * resolves the contract's implementation from the container - is replaced by one that calls every request an
 * impostor. The path is unique and does not exist: routed, it would be answered with a 404, so a 403 can only come
 * from the refusal.
 *
 * The container keeps the plugin it built for the first dispatch, with the verifier behind it, for the rest of the
 * process, so this holds only as the first front-controller dispatch of its PHPUnit run.
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
        $objectManager->removeSharedInstance(RequestVerifier::class);
        $objectManager->addSharedInstance(self::impostorVerifier(), RequestVerifier::class);
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
        $objectManager->removeSharedInstance(RequestVerifier::class);
        $_SERVER = $this->server ?? $_SERVER;
        parent::tearDown();
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
     * A request verifier that judges every request an impostor of a neutral crawler, with a verdict built by the
     * container's factory
     *
     * @return RequestVerifierInterface
     */
    private static function impostorVerifier(): RequestVerifierInterface
    {
        $factory = Bootstrap::getObjectManager()->get(VerdictInterfaceFactory::class);
        self::assertInstanceOf(VerdictInterfaceFactory::class, $factory);
        $verdict = $factory->create(['status' => VerdictStatus::Impostor, 'crawlerCode' => 'example']);

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
