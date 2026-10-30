<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\TimeSourceInterface;

/**
 * A clock that stands still at the moment it is given
 */
class FixedTimeSource implements TimeSourceInterface
{
    /**
     * @param int $now The moment the clock shows, unix seconds
     */
    public function __construct(
        private readonly int $now
    ) {
    }

    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return $this->now;
    }
}
