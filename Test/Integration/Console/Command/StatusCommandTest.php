<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Console\Command;

use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Console\Command\CheckCommand;
use Hryvinskyi\BotVerification\Console\Command\RefreshCommand;
use Hryvinskyi\BotVerification\Console\Command\StatusCommand;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Range\SnapshotFreshness;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use Magento\Framework\App\Cache\StateInterface;
use Magento\Framework\ObjectManager\ConfigInterface;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\ObjectManager;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bot-verification:status` as the container builds it, over a neutral registry of two crawler lists and one proxy
 * list, the real snapshot storage behind the module's cache, which is on, and a clock that stands still
 *
 * Snapshots are saved through one fresh repository and read by the command through another, so what it reports comes
 * from the cache or the flag table, never from memory.
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification enabled
 */
class StatusCommandTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const HOUR = 3600;
    private const DAY = 86400;

    private const FRESH = 'example_fresh';
    private const STALE = 'example_stale';
    private const EDGE = 'example_edge';

    /**
     * Name of the virtual type that stands for a registry whose declaration is malformed
     */
    private const MALFORMED_REGISTRY = 'HryvinskyiBotVerificationTestMalformedCrawlerRegistry';

    /**
     * The registry preference to put back after a test replaced it; null while it is in place
     *
     * @var string|null
     */
    private ?string $replacedRegistryPreference = null;

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
        if ($this->replacedRegistryPreference !== null) {
            Bootstrap::getObjectManager()->configure([
                'preferences' => [CrawlerRegistryInterface::class => $this->replacedRegistryPreference],
            ]);
            self::testObjectManager()->removeSharedInstance(self::registryProxy());
            $this->replacedRegistryPreference = null;
        }
    }

    /**
     * A fresh list is reported with its size and age, a stale one as stale, a list never stored as never fetched -
     * the proxy list as much as the crawler lists - and the command fails
     *
     * @return void
     */
    public function testReportsFreshStaleAndMissingListsAndFails(): void
    {
        self::save(self::FRESH, ['192.0.2.0/25', '198.51.100.0/24'], self::NOW - 5 * self::HOUR);
        self::save(
            self::STALE,
            ['203.0.113.0/27', '203.0.113.64/27', '2001:db8:4801::/64'],
            self::NOW - 8 * self::DAY
        );
        $tester = self::tester(self::registry());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            "example_fresh: 2 ranges, fetched 5 h ago\n"
            . "example_stale: 3 ranges, fetched 192 h ago — stale\n"
            . "example_edge: never fetched\n"
            . "Maximum age: 7 days\n"
            . "Cache type bot_verification: enabled\n",
            $tester->getDisplay()
        );
    }

    /**
     * The stale and the missing list are printed as errors, the fresh one is not
     *
     * @return void
     */
    public function testPrintsStaleAndMissingListsAsErrors(): void
    {
        self::save(self::FRESH, ['192.0.2.0/25'], self::NOW - 5 * self::HOUR);
        self::save(self::STALE, ['203.0.113.0/27'], self::NOW - 8 * self::DAY);
        $tester = self::tester(self::registry());

        $tester->execute([], ['decorated' => true]);

        self::assertSame(
            "example_fresh: 1 ranges, fetched 5 h ago\n"
            . self::errorLine('example_stale: 1 ranges, fetched 192 h ago — stale') . "\n"
            . self::errorLine('example_edge: never fetched') . "\n"
            . "Maximum age: 7 days\n"
            . "Cache type bot_verification: enabled\n",
            $tester->getDisplay()
        );
    }

    /**
     * When every list is stored and none is older than the maximum age - one exactly that old - the command succeeds
     *
     * @return void
     */
    public function testSucceedsWhenEveryListIsFresh(): void
    {
        self::save(self::FRESH, ['192.0.2.0/25'], self::NOW);
        self::save(self::STALE, ['203.0.113.0/27'], self::NOW - 7 * self::DAY);
        self::save(self::EDGE, ['198.51.100.0/24', '2001:db8:4801::/64'], self::NOW - 30 * self::HOUR - 59);
        $tester = self::tester(self::registry());

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "example_fresh: 1 ranges, fetched 0 h ago\n"
            . "example_stale: 1 ranges, fetched 168 h ago\n"
            . "example_edge: 2 ranges, fetched 30 h ago\n"
            . "Maximum age: 7 days\n"
            . "Cache type bot_verification: enabled\n",
            $tester->getDisplay()
        );
    }

    /**
     * With no list declared there is nothing to watch, and the command says so and succeeds
     *
     * @return void
     */
    public function testAnEmptyRegistryIsReportedAndSucceeds(): void
    {
        $registry = Bootstrap::getObjectManager()->create(CrawlerRegistry::class, [
            'crawlers' => [],
            'rangeLists' => [],
            'rangeListFormats' => [],
            'proxyLists' => [],
        ]);
        self::assertInstanceOf(CrawlerRegistry::class, $registry);
        $tester = self::tester($registry);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("No address lists are declared.\n", $tester->getDisplay());
    }

    /**
     * Every console command is built whenever bin/magento starts, and a failure to build one fails every command. A
     * malformed list declaration therefore leaves the three commands buildable as the container wires them, and fails
     * the status command only when it runs, with the registry's message
     *
     * @return void
     */
    public function testAMalformedDeclarationFailsOnlyTheCommandThatRuns(): void
    {
        $objectManager = self::testObjectManager();
        $diConfig = $objectManager->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $diConfig);
        $this->replacedRegistryPreference = $diConfig->getPreference(CrawlerRegistryInterface::class);
        // A virtual type inherits every argument it does not set from its type's configuration, which the enabled
        // modules declaring crawlers, lists or proxies contribute to: all four are set, so the one malformed list
        // is the only fault the registry can report.
        $objectManager->configure([
            'preferences' => [CrawlerRegistryInterface::class => self::MALFORMED_REGISTRY],
            self::MALFORMED_REGISTRY => [
                'type' => CrawlerRegistry::class,
                'arguments' => [
                    'crawlers' => [],
                    'rangeLists' => ['example_plain' => 'http://example.com/list.json'],
                    'rangeListFormats' => [],
                    'proxyLists' => [],
                ],
            ],
        ]);
        $objectManager->removeSharedInstance(self::registryProxy());

        self::assertInstanceOf(RefreshCommand::class, $objectManager->create(RefreshCommand::class));
        self::assertInstanceOf(CheckCommand::class, $objectManager->create(CheckCommand::class));
        $status = $objectManager->create(StatusCommand::class);
        self::assertInstanceOf(StatusCommand::class, $status);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('The address list "example_plain" must be published at an https:// URL.');
        (new CommandTester($status))->execute([]);
    }

    /**
     * A neutral registry: one crawler with two lists, and one proxy list in plain text
     *
     * @return CrawlerRegistry
     */
    private static function registry(): CrawlerRegistry
    {
        $registry = Bootstrap::getObjectManager()->create(CrawlerRegistry::class, [
            'crawlers' => [
                'example' => [
                    'tokens' => ['examplebot' => 'ExampleBot'],
                    'lists' => [self::FRESH => self::FRESH, self::STALE => self::STALE],
                ],
            ],
            'rangeLists' => [
                self::FRESH => 'https://example.com/fresh.json',
                self::STALE => 'https://example.com/stale.json',
                self::EDGE => 'https://example.com/edge-servers.txt',
            ],
            'rangeListFormats' => [self::EDGE => 'plain_text'],
            'proxyLists' => [self::EDGE => self::EDGE],
        ]);
        self::assertInstanceOf(CrawlerRegistry::class, $registry);

        return $registry;
    }

    /**
     * A tester of the command as the container builds it, with the given registry, a fresh repository, and a
     * freshness judged at the fixed moment the command also measures ages at
     *
     * @param CrawlerRegistry $registry The declared lists
     * @return CommandTester
     */
    private static function tester(CrawlerRegistry $registry): CommandTester
    {
        $objectManager = Bootstrap::getObjectManager();
        $clock = new FixedTimeSource(self::NOW);
        $command = $objectManager->create(StatusCommand::class, [
            'registry' => $registry,
            'snapshots' => self::freshRepository(),
            'freshness' => $objectManager->create(SnapshotFreshness::class, ['timeSource' => $clock]),
            'timeSource' => $clock,
        ]);
        self::assertInstanceOf(StatusCommand::class, $command);

        return new CommandTester($command);
    }

    /**
     * Store a snapshot of the given prefixes through a fresh repository
     *
     * @param string $listCode Code of the list
     * @param list<string> $prefixes CIDR prefixes of the list
     * @param int $fetchedAt Moment the list was fetched, unix seconds
     * @return void
     */
    private static function save(string $listCode, array $prefixes, int $fetchedAt): void
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);

        self::freshRepository()->save($listCode, $builder->build($prefixes, $fetchedAt));
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

    /**
     * A line as a decorated console prints it in the error style
     *
     * @param string $text Text of the line, free of console markup
     * @return string
     */
    private static function errorLine(string $text): string
    {
        return (new OutputFormatter(true))->format('<error>' . $text . '</error>') ?? '';
    }

    /**
     * The test framework's object manager, which can forget a shared instance
     *
     * @return ObjectManager
     */
    private static function testObjectManager(): ObjectManager
    {
        $objectManager = Bootstrap::getObjectManager();
        self::assertInstanceOf(ObjectManager::class, $objectManager);

        return $objectManager;
    }

    /**
     * Name of the generated lazy stand-in for the registry, which keeps the registry it built once asked
     *
     * @return string
     */
    private static function registryProxy(): string
    {
        return CrawlerRegistryInterface::class . '\Proxy';
    }
}
