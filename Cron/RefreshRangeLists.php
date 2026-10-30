<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Cron;

use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Psr\Log\LoggerInterface;

/**
 * The scheduled refresh of every declared address list
 *
 * Each list the refresh rejects keeps its previous snapshot and is logged as a warning, so a list whose fetch keeps
 * failing leaves a trail long before its snapshot grows too old to accuse anyone. Anything the refresh throws reaches
 * the cron runner, which records the run as failed.
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
     * A list that cannot be stored ends the refresh with the storage's exception, which is left to the cron runner.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->refresher->refresh()->getRejected() as $listCode => $reason) {
            $this->logger->warning(sprintf(
                'Bot verification: range list "%s" was not refreshed and keeps its previous snapshot: %s',
                $listCode,
                $reason
            ));
        }
    }
}
