<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Console\Command;

use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Console\Command\StatusCommand;
use Hryvinskyi\BotVerification\Model\Cache\Type\RangeSnapshotCache;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Storage\CachedRangeSnapshotRepository;
use Magento\Framework\App\Cache\StateInterface;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * `bot-verification:status` as the container builds it, with the container's cache state, while the module's cache
 * type is off: the lists are read from the database and reported, and the command fails on the cache type alone
 *
 * @magentoDbIsolation enabled
 * @magentoCache bot_verification disabled
 */
class StatusCommandCacheDisabledTest extends TestCase
{
    private const LIST_CODE = 'example_list';

    /**
     * A disabled cache type is printed as an error that says how to enable it, and fails a status whose every list
     * is fresh
     *
     * @return void
     */
    public function testADisabledCacheTypeFailsTheStatus(): void
    {
        $state = Bootstrap::getObjectManager()->get(StateInterface::class);
        self::assertInstanceOf(StateInterface::class, $state);
        self::assertFalse($state->isEnabled(RangeSnapshotCache::TYPE_IDENTIFIER));
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);
        self::freshRepository()->save(self::LIST_CODE, $builder->build(['192.0.2.0/24'], time()));
        $registry = Bootstrap::getObjectManager()->create(CrawlerRegistry::class, [
            'crawlers' => [],
            'rangeLists' => [self::LIST_CODE => 'https://bot.example/list.json'],
            'rangeListFormats' => [],
            'proxyLists' => [],
        ]);
        $command = Bootstrap::getObjectManager()->create(StatusCommand::class, [
            'registry' => $registry,
            'snapshots' => self::freshRepository(),
        ]);
        self::assertInstanceOf(StatusCommand::class, $command);
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([], ['decorated' => true]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            "example_list: 1 ranges, fetched 0 h ago\n"
            . "Maximum age: 7 days\n"
            . (new OutputFormatter(true))->format(
                '<error>Cache type bot_verification: disabled — every request that claims a crawler reads the lists'
                . ' from the database; run bin/magento cache:enable bot_verification</error>'
            )
            . "\n",
            $tester->getDisplay()
        );
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
}
