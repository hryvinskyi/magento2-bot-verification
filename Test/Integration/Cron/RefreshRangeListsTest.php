<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Cron;

use Hryvinskyi\BotVerification\Cron\RefreshRangeLists;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Hryvinskyi\BotVerification\Exception\RangeListStorageException;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Hryvinskyi\BotVerification\Model\RangeListRefresher;
use Hryvinskyi\BotVerification\Test\Double\RecordingLogger;
use Hryvinskyi\BotVerification\Test\Double\SaveFailingRangeSnapshotRepository;
use Hryvinskyi\BotVerification\Test\Double\ScriptedRangeListFetcher;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;

/**
 * The scheduled refresh as the container builds it, over the real refresher - neutral registry, a fetcher that
 * answers in place of the network, a storage that fails on one list - and a logger that records what it is given
 */
class RefreshRangeListsTest extends TestCase
{
    private const REJECTED = 'example_rejected';
    private const UNSTORABLE = 'example_unstorable';
    private const AFTER = 'example_after';

    private const REJECTED_URL = 'https://example.com/rejected.json';
    private const UNSTORABLE_URL = 'https://example.com/unstorable.json';
    private const AFTER_URL = 'https://example.com/after.json';

    /**
     * A list rejected before a later list could not be stored is logged as a warning, and the storage failure still
     * reaches the cron runner; the list after the failure is not fetched
     *
     * @return void
     */
    public function testLogsTheRejectionRecordedBeforeAStorageFailureAndRethrowsIt(): void
    {
        $objectManager = Bootstrap::getObjectManager();
        $fetcher = new ScriptedRangeListFetcher([
            self::REJECTED_URL => new RangeListRejectedException(__('The list could not be fetched.')),
            self::UNSTORABLE_URL => ['192.0.2.0/24'],
            self::AFTER_URL => ['198.51.100.0/24'],
        ]);
        $refresher = $objectManager->create(RangeListRefresher::class, [
            'registry' => $objectManager->create(CrawlerRegistry::class, [
                'crawlers' => [
                    'example' => [
                        'tokens' => ['examplebot' => 'ExampleBot'],
                        'lists' => [
                            self::REJECTED => self::REJECTED,
                            self::UNSTORABLE => self::UNSTORABLE,
                            self::AFTER => self::AFTER,
                        ],
                    ],
                ],
                'rangeLists' => [
                    self::REJECTED => self::REJECTED_URL,
                    self::UNSTORABLE => self::UNSTORABLE_URL,
                    self::AFTER => self::AFTER_URL,
                ],
                'rangeListFormats' => [],
                'proxyLists' => [],
            ]),
            'fetcher' => $fetcher,
            'snapshots' => new SaveFailingRangeSnapshotRepository(
                self::UNSTORABLE,
                new \RuntimeException('The storage is unavailable.')
            ),
        ]);
        $logger = new RecordingLogger();
        $cron = $objectManager->create(RefreshRangeLists::class, ['refresher' => $refresher, 'logger' => $logger]);
        self::assertInstanceOf(RefreshRangeLists::class, $cron);

        $caught = null;
        try {
            $cron->execute();
        } catch (RangeListStorageException $exception) {
            $caught = $exception;
        }

        self::assertNotNull($caught, 'The storage failure did not reach the cron runner.');
        self::assertSame(self::UNSTORABLE, $caught->getListCode());
        self::assertSame(
            [
                [
                    'level' => LogLevel::WARNING,
                    'message' => 'Bot verification: range list "example_rejected" was not refreshed and keeps its '
                        . 'previous snapshot: The list could not be fetched.',
                ],
            ],
            $logger->records
        );
        self::assertSame([self::REJECTED_URL, self::UNSTORABLE_URL], array_column($fetcher->requests, 'url'));
    }
}
