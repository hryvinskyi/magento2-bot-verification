<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Cron;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterfaceFactory;
use Hryvinskyi\BotVerification\Cron\RefreshRangeLists;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListRefresher;
use Magento\Cron\Model\ConfigInterface;
use Magento\Cron\Model\Schedule;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The scheduled refresh is registered where the cron runner reads its jobs - the merged cron configuration - and the
 * class and method it names run the refresh when called the way the runner calls them
 *
 * Without the registration nothing would ever be refreshed, every claim would stay unverifiable, and every other test
 * would stay green.
 */
class RefreshRangeListsWiringTest extends TestCase
{
    private const GROUP = 'default';
    private const JOB = 'hryvinskyi_bot_verification_refresh';

    /**
     * The job is in the default group, names the refresh class and method, and runs every six hours at minute 17
     *
     * @return void
     */
    public function testTheJobIsRegisteredInTheMergedCronConfiguration(): void
    {
        $job = self::job();

        self::assertSame(self::JOB, $job['name'] ?? null);
        self::assertSame(RefreshRangeLists::class, $job['instance'] ?? null);
        self::assertSame('execute', $job['method'] ?? null);
        self::assertSame('17 */6 * * *', $job['schedule'] ?? null);
    }

    /**
     * The configured class, built by the container, and the configured method, called with the schedule as the
     * runner calls it, ask for a refresh of every list without accepting a shrink
     *
     * @return void
     */
    public function testTheConfiguredCallbackRunsTheRefresh(): void
    {
        $job = self::job();
        $instance = $job['instance'] ?? null;
        $method = $job['method'] ?? null;
        self::assertIsString($instance);
        self::assertIsString($method);
        $objectManager = Bootstrap::getObjectManager();
        $resultFactory = $objectManager->get(RefreshResultInterfaceFactory::class);
        self::assertInstanceOf(RefreshResultInterfaceFactory::class, $resultFactory);
        $refresher = new ScriptedRangeListRefresher($resultFactory->create([
            'refreshed' => ['example_main' => 3],
            'rejected' => [],
        ]));

        $callback = [$objectManager->create($instance, ['refresher' => $refresher]), $method];
        self::assertIsCallable($callback);
        call_user_func_array($callback, [$objectManager->create(Schedule::class)]);

        self::assertSame([['listCodes' => [], 'acceptShrink' => false]], $refresher->calls);
    }

    /**
     * The job's merged configuration
     *
     * @return array<mixed>
     */
    private static function job(): array
    {
        $config = Bootstrap::getObjectManager()->get(ConfigInterface::class);
        self::assertInstanceOf(ConfigInterface::class, $config);
        $jobs = $config->getJobs();
        $group = $jobs[self::GROUP] ?? null;
        self::assertIsArray($group, 'The cron configuration has no default group.');
        $job = $group[self::JOB] ?? null;
        self::assertIsArray($job, 'The refresh is not registered in the default cron group.');

        return $job;
    }
}
