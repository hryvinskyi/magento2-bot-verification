<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Cron;

use Hryvinskyi\BotVerification\Cron\RefreshRangeLists;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListRefresher;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The scheduled refresh refreshes every declared list and warns once per list that keeps its previous snapshot
 */
class RefreshRangeListsTest extends TestCase
{
    /**
     * A rejected list is logged once as a warning naming the list and the reason; a refreshed one is not logged
     *
     * @return void
     */
    public function testWarnsOncePerRejectedList(): void
    {
        $refresher = new ScriptedRangeListRefresher(new RefreshResult(
            ['example_main' => 12],
            ['example_extra' => 'The list could not be fetched.']
        ));
        $logger = $this->logger();
        $logger->expects(self::once())
            ->method('warning')
            ->with(
                'Bot verification: range list "example_extra" was not refreshed and keeps its previous snapshot: '
                . 'The list could not be fetched.'
            );
        $logger->expects(self::never())->method(self::logicalNot(self::equalTo('warning')));

        (new RefreshRangeLists($refresher, $logger))->execute();

        self::assertSame([['listCodes' => [], 'acceptShrink' => false]], $refresher->calls);
    }

    /**
     * A refresh that rejects nothing logs nothing
     *
     * @return void
     */
    public function testLogsNothingWhenNoListIsRejected(): void
    {
        $refresher = new ScriptedRangeListRefresher(new RefreshResult(['example_main' => 12, 'example_extra' => 3]));
        $logger = $this->logger();
        $logger->expects(self::never())->method(self::anything());

        (new RefreshRangeLists($refresher, $logger))->execute();

        self::assertCount(1, $refresher->calls);
    }

    /**
     * A logger that records how it is called
     *
     * @return LoggerInterface&MockObject
     */
    private function logger(): LoggerInterface
    {
        return $this->createMock(LoggerInterface::class);
    }
}
