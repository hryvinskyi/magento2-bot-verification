<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api\Data;

/**
 * What one refresh did with each list it was asked to refresh
 *
 * Immutable. Every list it covers is either refreshed or rejected, never both.
 *
 * @api
 */
interface RefreshResultInterface
{
    /**
     * The lists the refresh stored
     *
     * @return array<string, int> List code => ranges stored, in the order the lists were refreshed
     */
    public function getRefreshed(): array;

    /**
     * The lists the refresh rejected, whose previous snapshots stay in force
     *
     * @return array<string, string> List code => why the list was rejected, in the order the lists were refreshed
     */
    public function getRejected(): array;
}
