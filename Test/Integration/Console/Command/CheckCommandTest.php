<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Console\Command;

use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterfaceFactory;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Console\Command\CheckCommand;
use Hryvinskyi\BotVerification\Console\Command\RefreshCommand;
use Hryvinskyi\BotVerification\Console\Command\StatusCommand;
use Hryvinskyi\BotVerification\Model\Address\ForwardingChainResolver;
use Hryvinskyi\BotVerification\Model\Address\TrustedProxyRanges;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Crawler\UserAgentClaimMatcher;
use Hryvinskyi\BotVerification\Model\CrawlerVerifier;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\FixedCrawlerVerifier;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\Console\CommandListInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bot-verification:check` as the container builds it, over a verifier that answers with a given verdict; with
 * --forwarded-for, over the real verification and chain walk of a neutral crawler and edge list kept in the real
 * snapshot storage; and the registration of the module's commands in the application's command list
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class CheckCommandTest extends TestCase
{
    private const USER_AGENT = 'Mozilla/5.0 (compatible; ExampleBot/1.0)';
    private const CLIENT_IP = '203.0.113.7';
    private const CRAWLER_LIST = 'example_list';
    private const EDGE_LIST = 'example_edge';

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
     * A verdict about a crawler is printed with its status and the crawler's code, and the User-Agent and address
     * reach the verifier as given
     *
     * @return void
     */
    public function testPrintsTheVerdictAndTheCrawler(): void
    {
        $verifier = new FixedCrawlerVerifier(self::verdict(VerdictStatus::Genuine, 'example'));
        $tester = self::tester($verifier);

        $exitCode = $tester->execute(['ip' => self::CLIENT_IP, 'user-agent' => self::USER_AGENT]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("Verdict: genuine\nCrawler: example\n", $tester->getDisplay());
        self::assertSame([['userAgent' => self::USER_AGENT, 'clientIp' => self::CLIENT_IP]], $verifier->calls);
    }

    /**
     * A verdict about no crawler is printed with a dash for the crawler
     *
     * @return void
     */
    public function testAVerdictAboutNoCrawlerPrintsADash(): void
    {
        $tester = self::tester(new FixedCrawlerVerifier(self::verdict(VerdictStatus::None)));

        $exitCode = $tester->execute(['ip' => self::CLIENT_IP, 'user-agent' => 'Mozilla/5.0']);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("Verdict: none\nCrawler: -\n", $tester->getDisplay());
    }

    /**
     * With --forwarded-for the address argument is the connecting address: the client is resolved past the own proxy
     * and the one edge server, printed, and judged
     *
     * @return void
     */
    public function testResolvesTheClientOfAForwardingChain(): void
    {
        self::seed();
        $tester = self::chainTester();

        $exitCode = $tester->execute([
            'ip' => '127.0.0.1',
            'user-agent' => self::USER_AGENT,
            '--forwarded-for' => '198.51.100.7, 192.0.2.10, 127.0.0.1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "Client address: 198.51.100.7\nVerdict: genuine\nCrawler: example\n",
            $tester->getDisplay()
        );
    }

    /**
     * A chain whose client cannot be determined is said to be so, and a claim judged at no address is unverifiable
     *
     * @return void
     */
    public function testAChainWithoutAClientIsUnverifiable(): void
    {
        self::seed();
        $tester = self::chainTester();

        $exitCode = $tester->execute([
            'ip' => '127.0.0.1',
            'user-agent' => self::USER_AGENT,
            '--forwarded-for' => '192.0.2.10, 127.0.0.1',
        ]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "Client address: cannot be determined\nVerdict: unverifiable\nCrawler: example\n",
            $tester->getDisplay()
        );
    }

    /**
     * The application's command list - what bin/magento offers - holds the three commands under their names
     *
     * @return void
     */
    public function testTheCommandsAreRegistered(): void
    {
        $commandList = Bootstrap::getObjectManager()->get(CommandListInterface::class);
        self::assertInstanceOf(CommandListInterface::class, $commandList);

        $byName = [];
        foreach ($commandList->getCommands() as $command) {
            $byName[(string) $command->getName()] = $command;
        }

        self::assertInstanceOf(RefreshCommand::class, $byName['bot-verification:refresh'] ?? null);
        self::assertInstanceOf(CheckCommand::class, $byName['bot-verification:check'] ?? null);
        self::assertInstanceOf(StatusCommand::class, $byName['bot-verification:status'] ?? null);
    }

    /**
     * A tester of the command as the container builds it, with the given verifier
     *
     * @param FixedCrawlerVerifier $verifier Answers in place of the real verification
     * @return CommandTester
     */
    private static function tester(FixedCrawlerVerifier $verifier): CommandTester
    {
        $command = Bootstrap::getObjectManager()->create(CheckCommand::class, ['verifier' => $verifier]);
        self::assertInstanceOf(CheckCommand::class, $command);

        return new CommandTester($command);
    }

    /**
     * A verdict built by the generated factory
     *
     * @param VerdictStatus $status Status of the verdict
     * @param string|null $crawlerCode Code of the crawler it is about; null for None
     * @return VerdictInterface
     */
    private static function verdict(VerdictStatus $status, ?string $crawlerCode = null): VerdictInterface
    {
        $factory = Bootstrap::getObjectManager()->get(VerdictInterfaceFactory::class);
        self::assertInstanceOf(VerdictInterfaceFactory::class, $factory);

        return $factory->create(['status' => $status, 'crawlerCode' => $crawlerCode]);
    }

    /**
     * A tester of the command as the container builds it, over the real verifier and chain walk of a neutral crawler
     * and edge list, each reading through its own fresh snapshot repository
     *
     * @return CommandTester
     */
    private static function chainTester(): CommandTester
    {
        $objectManager = Bootstrap::getObjectManager();
        $registry = $objectManager->create(CrawlerRegistry::class, [
            'crawlers' => [
                'example' => [
                    'tokens' => ['examplebot' => 'ExampleBot'],
                    'lists' => [self::CRAWLER_LIST => self::CRAWLER_LIST],
                ],
            ],
            'rangeLists' => [
                self::CRAWLER_LIST => 'https://bot.example/example.json',
                self::EDGE_LIST => 'https://edge.example/edge-servers.txt',
            ],
            'rangeListFormats' => [self::EDGE_LIST => 'plain_text'],
            'proxyLists' => [self::EDGE_LIST => self::EDGE_LIST],
        ]);
        $verifier = $objectManager->create(CrawlerVerifier::class, [
            'registry' => $registry,
            'claimMatcher' => $objectManager->create(UserAgentClaimMatcher::class, ['registry' => $registry]),
            'snapshots' => self::freshRepository(),
        ]);
        $trustedProxies = $objectManager->create(TrustedProxyRanges::class, [
            'registry' => $registry,
            'snapshots' => self::freshRepository(),
        ]);
        $command = $objectManager->create(CheckCommand::class, [
            'verifier' => $verifier,
            'chainResolver' => $objectManager->create(
                ForwardingChainResolver::class,
                ['trustedProxies' => $trustedProxies]
            ),
        ]);
        self::assertInstanceOf(CheckCommand::class, $command);

        return new CommandTester($command);
    }

    /**
     * Store the neutral crawler's list (198.51.100.0/24) and the edge list (192.0.2.0/24), fetched now
     *
     * @return void
     */
    private static function seed(): void
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);
        $repository = self::freshRepository();
        $repository->save(self::CRAWLER_LIST, $builder->build(['198.51.100.0/24'], time()));
        $repository->save(self::EDGE_LIST, $builder->build(['192.0.2.0/24'], time()));
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
