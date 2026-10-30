<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * Finds the known crawlers a User-Agent claims to be
 */
interface ClaimMatcherInterface
{
    /**
     * Codes of every crawler the User-Agent claims to be
     *
     * @param string $userAgent Raw User-Agent header ('' when absent)
     * @return list<string> Crawler codes, each once, in order of first occurrence; [] when it claims none
     */
    public function claims(string $userAgent): array;
}
