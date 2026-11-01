<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Cron;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Hryvinskyi\BotVerification\Exception\RangeListStorageException;
use Magento\Framework\Exception\LocalizedException;
use Psr\Log\LoggerInterface;

/**
 * The scheduled refresh of every declared address list
 *
 * Each list the refresh rejects keeps its previous snapshot and is logged as a warning, so a list whose fetch keeps
 * failing leaves a trail long before its snapshot grows too old to accuse anyone. That holds when a later list cannot
 * be stored too: the lists rejected before it are logged, and the storage failure is rethrown. Anything the refresh
 * throws reaches the cron runner, which records the run as failed.
 */
class RefreshRangeLists
{
    /**
     * @param RangeListRefresherInterface $refresher Brings the stored snapshots up to date
     * @param LoggerInterface $logger Where rejected lists are reported
     */
    public function __construct(
        private readonly RangeListRefresherInterface $refresher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Refresh every declared list and warn about each one that keeps its previous snapshot
     *
     * @return void
     * @throws RangeListStorageException When a list cannot be stored, after the lists rejected before it are logged
     * @throws LocalizedException When the refresh fails otherwise
     */
    public function execute(): void
    {
        try {
            $result = $this->refresher->refresh();
        } catch (RangeListStorageException $exception) {
            $this->warnAboutRejected($exception->getResult());
            throw $exception;
        }

        $this->warnAboutRejected($result);
    }

    /**
     * Log one warning per rejected list, naming the list and the reason
     *
     * @param RefreshResultInterface $result What a refresh did with each list
     * @return void
     */
    private function warnAboutRejected(RefreshResultInterface $result): void
    {
        foreach ($result->getRejected() as $listCode => $reason) {
            $this->logger->warning(sprintf(
                'Bot verification: range list "%s" was not refreshed and keeps its previous snapshot: %s',
                $listCode,
                $reason
            ));
        }
    }
}
