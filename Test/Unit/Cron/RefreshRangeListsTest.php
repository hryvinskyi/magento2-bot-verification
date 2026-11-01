<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Cron;

use Hryvinskyi\BotVerification\Cron\RefreshRangeLists;
use Hryvinskyi\BotVerification\Exception\RangeListStorageException;
use Hryvinskyi\BotVerification\Model\RefreshResult;
use Hryvinskyi\BotVerification\Test\Double\RecordingLogger;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListRefresher;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The scheduled refresh refreshes every declared list and warns once per list that keeps its previous snapshot,
 * also when a later list could not be stored
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
        $logger = new RecordingLogger();

        (new RefreshRangeLists($refresher, $logger))->execute();

        self::assertSame(
            [
                [
                    'level' => LogLevel::WARNING,
                    'message' => 'Bot verification: range list "example_extra" was not refreshed and keeps its '
                        . 'previous snapshot: The list could not be fetched.',
                ],
            ],
            $logger->records
        );
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
        $logger = new RecordingLogger();

        (new RefreshRangeLists($refresher, $logger))->execute();

        self::assertSame([], $logger->records);
        self::assertCount(1, $refresher->calls);
    }

    /**
     * A list rejected before a later list could not be stored is still logged, and the storage failure still
     * reaches the cron runner
     *
     * @return void
     */
    public function testWarnsAboutListsRejectedBeforeAStorageFailureAndRethrowsIt(): void
    {
        $failure = new RangeListStorageException(
            __('The address list %1 could not be stored and the refresh stopped: %2', 'example_edge', 'Unavailable.'),
            'example_edge',
            new RefreshResult(['example_extra' => 3], ['example_main' => 'The list could not be fetched.']),
            new \RuntimeException('Unavailable.')
        );
        $logger = new RecordingLogger();

        $caught = null;
        try {
            (new RefreshRangeLists(new ScriptedRangeListRefresher($failure), $logger))->execute();
        } catch (RangeListStorageException $exception) {
            $caught = $exception;
        }

        self::assertSame($failure, $caught, 'The storage failure did not reach the cron runner.');
        self::assertSame(
            [
                [
                    'level' => LogLevel::WARNING,
                    'message' => 'Bot verification: range list "example_main" was not refreshed and keeps its '
                        . 'previous snapshot: The list could not be fetched.',
                ],
            ],
            $logger->records
        );
    }
}
