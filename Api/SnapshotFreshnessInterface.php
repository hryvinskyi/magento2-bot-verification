<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;

/**
 * How old a snapshot of a published address list may be and still accuse an address
 *
 * A stale snapshot can still vouch for an address it holds, but it no longer proves that an address it lacks is not
 * the owner's: the owner may have added ranges since.
 */
interface SnapshotFreshnessInterface
{
    /**
     * Whether a snapshot is too old to accuse anyone
     *
     * A snapshot exactly the maximum age old is still fresh.
     *
     * @param RangeSnapshotInterface $snapshot Snapshot to judge
     * @return bool
     */
    public function isStale(RangeSnapshotInterface $snapshot): bool;

    /**
     * Maximum age a snapshot may have and still accuse
     *
     * @return int Seconds, positive
     */
    public function getMaxAge(): int;
}
