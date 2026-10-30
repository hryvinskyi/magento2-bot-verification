<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model;

use Hryvinskyi\BotVerification\Api\ClaimMatcherInterface;
use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\CrawlerVerifierInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Api\RequestVerifierInterface;
use Hryvinskyi\BotVerification\Api\SnapshotFreshnessInterface;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Crawler\UserAgentClaimMatcher;
use Hryvinskyi\BotVerification\Model\CrawlerVerifier;
use Hryvinskyi\BotVerification\Model\Range\SnapshotFreshness;
use Hryvinskyi\BotVerification\Model\RequestVerifier;
use Hryvinskyi\BotVerification\Model\Verdict;
use Hryvinskyi\BotVerification\Test\Double\CountingClientAddressResolver;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use Laminas\Stdlib\Parameters;
use Magento\Framework\HTTP\Header;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

/**
 * The verifiers as the container builds them: their preferences, the generated verdict factory, the real address
 * parser and the snapshot freshness configured for the module.
 *
 * The snapshots come from an in-memory repository, and the client address from a fixed resolver: neither the
 * storage nor the resolution of the address is under test here.
 */
class CrawlerVerifierTest extends TestCase
{
    private const EXAMPLE_UA = 'Mozilla/5.0 (compatible; ExampleBot/2.1; +http://bot.example/)';
    private const BROWSER_UA = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) '
        . 'Chrome/129.0.0.0 Safari/537.36';

    /**
     * A crawler verifier created by the container judges genuine, impostor and None through the generated verdict
     * factory
     *
     * @return void
     */
    public function testJudgesThroughTheContainer(): void
    {
        $verifier = self::crawlerVerifier(self::seededRepository());

        $genuine = $verifier->verify(self::EXAMPLE_UA, '203.0.113.7');
        $impostor = $verifier->verify(self::EXAMPLE_UA, '203.0.113.200');
        $none = $verifier->verify(self::BROWSER_UA, '203.0.113.200');

        self::assertInstanceOf(Verdict::class, $genuine);
        self::assertSame(VerdictStatus::Genuine, $genuine->getStatus());
        self::assertSame('example', $genuine->getCrawlerCode());
        self::assertSame(VerdictStatus::Impostor, $impostor->getStatus());
        self::assertSame('example', $impostor->getCrawlerCode());
        self::assertSame(VerdictStatus::None, $none->getStatus());
        self::assertNull($none->getCrawlerCode());
    }

    /**
     * A private address read by the container's address parser cannot be accused
     *
     * @return void
     */
    public function testAPrivateAddressIsUnverifiable(): void
    {
        $verdict = self::crawlerVerifier(self::seededRepository())->verify(self::EXAMPLE_UA, '10.0.0.1');

        self::assertSame(VerdictStatus::Unverifiable, $verdict->getStatus());
        self::assertSame('example', $verdict->getCrawlerCode());
    }

    /**
     * The container fills every contract of the verification with its implementation
     *
     * @return void
     */
    public function testTheContainerFillsTheContracts(): void
    {
        $objectManager = Bootstrap::getObjectManager();

        self::assertInstanceOf(CrawlerVerifier::class, $objectManager->get(CrawlerVerifierInterface::class));
        self::assertInstanceOf(UserAgentClaimMatcher::class, $objectManager->get(ClaimMatcherInterface::class));
        self::assertInstanceOf(CrawlerRegistry::class, $objectManager->get(CrawlerRegistryInterface::class));
        self::assertInstanceOf(RequestVerifier::class, $objectManager->get(RequestVerifierInterface::class));
        $freshness = $objectManager->get(SnapshotFreshnessInterface::class);
        self::assertInstanceOf(SnapshotFreshness::class, $freshness);
        self::assertSame(604800, $freshness->getMaxAge());
    }

    /**
     * A request verifier created by the container with only the client address replaced judges the shared request,
     * and asks for no address while nothing is claimed
     *
     * @return void
     */
    public function testRequestVerifierIsWiredByTheContainer(): void
    {
        $resolver = new CountingClientAddressResolver('203.0.113.7');

        $verifier = self::create(RequestVerifier::class, ['clientAddressResolver' => $resolver]);
        $verdict = $verifier->verify();

        self::assertSame(VerdictStatus::None, $verdict->getStatus());
        self::assertSame(0, $resolver->calls);
    }

    /**
     * A request verifier judges the request it reads the User-Agent from, at the address the resolver gives
     *
     * @return void
     */
    public function testRequestVerifierJudgesTheRequest(): void
    {
        $request = self::create(Request::class);
        $request->setServer(new Parameters(['HTTP_USER_AGENT' => self::EXAMPLE_UA]));
        $snapshots = self::seededRepository();
        $resolver = new CountingClientAddressResolver('203.0.113.200');

        $verifier = self::create(RequestVerifier::class, [
            'header' => self::create(Header::class, ['httpRequest' => $request]),
            'claimMatcher' => self::create(UserAgentClaimMatcher::class, ['registry' => self::registry()]),
            'clientAddressResolver' => $resolver,
            'crawlerVerifier' => self::crawlerVerifier($snapshots),
        ]);
        $verdict = $verifier->verify();

        self::assertSame(VerdictStatus::Impostor, $verdict->getStatus());
        self::assertSame('example', $verdict->getCrawlerCode());
        self::assertSame(1, $resolver->calls);
    }

    /**
     * A crawler verifier from the container over a neutral crawler and the given snapshots
     *
     * @param InMemoryRangeSnapshotRepository $snapshots Snapshots to judge against
     * @return CrawlerVerifier
     */
    private static function crawlerVerifier(InMemoryRangeSnapshotRepository $snapshots): CrawlerVerifier
    {
        $registry = self::registry();

        return self::create(CrawlerVerifier::class, [
            'registry' => $registry,
            'claimMatcher' => self::create(UserAgentClaimMatcher::class, ['registry' => $registry]),
            'snapshots' => $snapshots,
        ]);
    }

    /**
     * A registry of one neutral crawler with one list, created by the container
     *
     * Every argument is passed, empty ones included: an argument left out is filled from the configuration that the
     * enabled modules declaring crawlers, lists or proxies contribute, and their lists would leak into this registry
     * - or make it throw, when one of them gives a format to a list that the lists passed here do not declare.
     *
     * @return CrawlerRegistry
     */
    private static function registry(): CrawlerRegistry
    {
        return self::create(CrawlerRegistry::class, [
            'crawlers' => ['example' => ['tokens' => ['examplebot' => 'ExampleBot'], 'lists' => ['example_list']]],
            'rangeLists' => ['example_list' => 'https://bot.example/example.json'],
            'rangeListFormats' => [],
            'proxyLists' => [],
        ]);
    }

    /**
     * An in-memory repository holding a snapshot of the neutral crawler's list, fetched now, built by the
     * container's builder
     *
     * @return InMemoryRangeSnapshotRepository
     */
    private static function seededRepository(): InMemoryRangeSnapshotRepository
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);
        $snapshots = new InMemoryRangeSnapshotRepository();
        $snapshots->save('example_list', $builder->build(['203.0.113.0/26'], time()));

        return $snapshots;
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
