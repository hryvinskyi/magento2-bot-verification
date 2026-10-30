<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Range;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\SnapshotFreshnessInterface;
use Hryvinskyi\BotVerification\Api\TimeSourceInterface;

/**
 * The one owner of the maximum age a snapshot may have and still accuse an address
 *
 * The verdict and every report on the lists' state ask this service, so they cannot disagree about which list is
 * stale.
 */
class SnapshotFreshness implements SnapshotFreshnessInterface
{
    /**
     * @param TimeSourceInterface $timeSource The current moment
     * @param int $maxAge Maximum age in seconds; a week by default
     * @throws \InvalidArgumentException When the maximum age is not positive
     */
    public function __construct(
        private readonly TimeSourceInterface $timeSource,
        private readonly int $maxAge = 604800
    ) {
        if ($maxAge <= 0) {
            throw new \InvalidArgumentException(sprintf(
                'The maximum age of an address list must be a positive number of seconds; %d given.',
                $maxAge
            ));
        }
    }

    /**
     * @inheritDoc
     */
    public function isStale(RangeSnapshotInterface $snapshot): bool
    {
        return $snapshot->isOlderThan($this->maxAge, $this->timeSource->now());
    }

    /**
     * @inheritDoc
     */
    public function getMaxAge(): int
    {
        return $this->maxAge;
    }
}
