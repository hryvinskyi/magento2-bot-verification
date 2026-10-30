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
 * Where the last snapshot of each published address list is kept, by the list's code
 */
interface RangeSnapshotRepositoryInterface
{
    /**
     * The last stored snapshot of a list
     *
     * @param string $listCode Code of the published address list
     * @return RangeSnapshotInterface|null Null when the list was never stored
     */
    public function find(string $listCode): ?RangeSnapshotInterface;

    /**
     * Keep a snapshot as the last one of a list, replacing the one stored before
     *
     * @param string $listCode Code of the published address list
     * @param RangeSnapshotInterface $snapshot Snapshot to keep
     * @return void
     */
    public function save(string $listCode, RangeSnapshotInterface $snapshot): void;
}
