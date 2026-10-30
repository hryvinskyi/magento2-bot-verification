<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\TimeSourceInterface;

/**
 * The system clock
 */
class SystemTimeSource implements TimeSourceInterface
{
    /**
     * @inheritDoc
     */
    public function now(): int
    {
        return time();
    }
}
