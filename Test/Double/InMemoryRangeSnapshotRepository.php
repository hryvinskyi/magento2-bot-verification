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
 * Keeps snapshots in memory and records which lists it was asked for and which it was given
 */
class InMemoryRangeSnapshotRepository implements RangeSnapshotRepositoryInterface
{
    /**
     * @var list<string> Codes of the lists asked for, in order, once per ask
     */
    public array $asked = [];

    /**
     * @var list<string> Codes of the lists saved, in order, once per save
     */
    public array $saved = [];

    /**
     * @var array<string, RangeSnapshotInterface> List code => snapshot
     */
    private array $snapshots = [];

    /**
     * @inheritDoc
     */
    public function find(string $listCode): ?RangeSnapshotInterface
    {
        $this->asked[] = $listCode;

        return $this->snapshots[$listCode] ?? null;
    }

    /**
     * @inheritDoc
     */
    public function save(string $listCode, RangeSnapshotInterface $snapshot): void
    {
        $this->saved[] = $listCode;
        $this->snapshots[$listCode] = $snapshot;
    }
}
