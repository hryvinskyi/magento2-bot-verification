<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;

/**
 * Keeps snapshots in memory, except that saving the one list it was told to fail on throws the given exception
 */
class SaveFailingRangeSnapshotRepository implements RangeSnapshotRepositoryInterface
{
    /**
     * @var list<string> Codes of the lists saved, in order, once per successful save
     */
    public array $saved = [];

    /**
     * @var array<string, RangeSnapshotInterface> List code => snapshot
     */
    private array $snapshots = [];

    /**
     * @param string $failingListCode Code of the list whose save throws
     * @param \Exception $failure What its save throws
     */
    public function __construct(
        private readonly string $failingListCode,
        private readonly \Exception $failure
    ) {
    }

    /**
     * @inheritDoc
     */
    public function find(string $listCode): ?RangeSnapshotInterface
    {
        return $this->snapshots[$listCode] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function save(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        if ($listCode === $this->failingListCode) {
            throw $this->failure;
        }
        $this->saved[] = $listCode;
        $this->snapshots[$listCode] = $snapshot;
    }
}
