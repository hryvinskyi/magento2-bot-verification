<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Console\Command;

use Hryvinskyi\BotVerification\Console\Command\StatusCommand;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\SnapshotFreshness;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use Hryvinskyi\BotVerification\Test\Double\InMemoryRangeSnapshotRepository;
use Magento\Framework\App\Cache\StateInterface;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * The status reports whether the module's cache type is on after the lists, and fails while it is off even when
 * every list is fresh; with no list declared it reports nothing about the cache.
 */
class StatusCommandTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const MAX_AGE = 604800;
    private const HOUR = 3600;

    /**
     * With the cache type on and every list fresh, the cache type is reported enabled and the command succeeds
     *
     * @return void
     */
    public function testAnEnabledCacheTypeIsReportedAndSucceeds(): void
    {
        $tester = $this->tester(true, ['example_list' => 'https://bot.example/list.json']);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame(
            "example_list: 1 ranges, fetched 2 h ago\n"
            . "Maximum age: 7 days\n"
            . "Cache type bot_verification: enabled\n",
            $tester->getDisplay()
        );
    }

    /**
     * With the cache type off the command says what that costs and how to turn it on, and fails although every list
     * is fresh
     *
     * @return void
     */
    public function testADisabledCacheTypeIsAnErrorAndFails(): void
    {
        $tester = $this->tester(false, ['example_list' => 'https://bot.example/list.json']);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::FAILURE, $exitCode);
        self::assertSame(
            "example_list: 1 ranges, fetched 2 h ago\n"
            . "Maximum age: 7 days\n"
            . 'Cache type bot_verification: disabled — every request that claims a crawler reads the lists from the'
            . " database; run bin/magento cache:enable bot_verification\n",
            $tester->getDisplay()
        );
    }

    /**
     * With no list declared nothing would be read, so the cache type is not reported, even when it is off
     *
     * @return void
     */
    public function testNoDeclaredListReportsNoCacheType(): void
    {
        $tester = $this->tester(false, []);

        $exitCode = $tester->execute([]);

        self::assertSame(Command::SUCCESS, $exitCode);
        self::assertSame("No address lists are declared.\n", $tester->getDisplay());
    }

    /**
     * A tester of the command over the given lists - each stored fresh with one range - and cache state
     *
     * @param bool $isCacheEnabled Whether the module's cache type is on
     * @param array<string, string> $rangeLists List code => URL
     * @return CommandTester
     */
    private function tester(bool $isCacheEnabled, array $rangeLists): CommandTester
    {
        $snapshots = new InMemoryRangeSnapshotRepository();
        foreach (array_keys($rangeLists) as $listCode) {
            $snapshots->save(
                $listCode,
                new RangeSnapshot(
                    self::packed('192.0.2.0') . self::packed('192.0.2.255'),
                    '',
                    self::NOW - 2 * self::HOUR
                )
            );
        }
        $cacheState = $this->createMock(StateInterface::class);
        $cacheState->method('isEnabled')->willReturnMap([['bot_verification', $isCacheEnabled]]);
        $clock = new FixedTimeSource(self::NOW);

        return new CommandTester(new StatusCommand(
            new CrawlerRegistry([], $rangeLists),
            $snapshots,
            new SnapshotFreshness($clock, self::MAX_AGE),
            $clock,
            $cacheState
        ));
    }

    /**
     * Packed form of an address
     *
     * @param string $address Valid address
     * @return string
     */
    private static function packed(string $address): string
    {
        $packed = inet_pton($address);
        self::assertIsString($packed);

        return $packed;
    }
}
