<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;

/**
 * The verdict on the current HTTP request's claim to be a known crawler
 *
 * @api
 */
interface RequestVerifierInterface
{
    /**
     * Verdict of the current HTTP request, computed at most once per request
     *
     * @return VerdictInterface
     */
    public function verify(): VerdictInterface;
}
