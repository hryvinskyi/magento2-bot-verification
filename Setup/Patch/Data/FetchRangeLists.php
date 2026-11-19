<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Setup\Patch\Data;

use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use Psr\Log\LoggerInterface;

/**
 * Fetches every declared address list once, in the setup:upgrade that installs the module
 *
 * Without it no list has a snapshot until the scheduled refresh first runs, up to six hours after a deploy, and every
 * crawler claim and every edge-server question is undecided until then. The fetch never fails the upgrade: a list that
 * is rejected, or a refresh that fails outright (no network on the build host, a list that cannot be stored), is
 * logged as a warning and left to the scheduled refresh. Provider modules installed later are fetched by that refresh,
 * or by `bin/magento bot-verification:refresh`.
 */
class FetchRangeLists implements DataPatchInterface
{
    /**
     * @param RangeListRefresherInterface $refresher Fetches and stores the declared lists
     * @param LoggerInterface $logger Where a list that could not be fetched is reported
     */
    public function __construct(
        private readonly RangeListRefresherInterface $refresher,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * Fetch every declared list, reporting instead of failing
     *
     * @return self
     */
    public function apply(): self
    {
        try {
            $rejected = $this->refresher->refresh()->getRejected();
        } catch (\Throwable $exception) {
            $this->logger->warning(
                'Bot verification: the address lists could not be fetched at installation and are left to the'
                . ' scheduled refresh: ' . $exception->getMessage(),
                ['exception' => $exception]
            );

            return $this;
        }

        foreach ($rejected as $listCode => $reason) {
            $this->logger->warning(sprintf(
                'Bot verification: range list "%s" could not be fetched at installation and is left to the'
                . ' scheduled refresh: %s',
                $listCode,
                $reason
            ));
        }

        return $this;
    }

    /**
     * @inheritDoc
     */
    public static function getDependencies(): array
    {
        return [];
    }

    /**
     * @inheritDoc
     */
    public function getAliases(): array
    {
        return [];
    }
}
