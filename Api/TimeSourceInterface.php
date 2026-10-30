<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * The current moment, for the age of fetched address lists
 */
interface TimeSourceInterface
{
    /**
     * The current moment
     *
     * @return int Unix seconds
     */
    public function now(): int;
}
