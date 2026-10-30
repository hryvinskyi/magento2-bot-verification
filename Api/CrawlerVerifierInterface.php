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
 * Judges whether a User-Agent's claim to be a known crawler is borne out by the client address
 *
 * Never throws for any input, and never makes a network call: it reads only address lists fetched beforehand.
 *
 * @api
 */
interface CrawlerVerifierInterface
{
    /**
     * Verdict on the claim a User-Agent makes, for a request from the given address
     *
     * @param string $userAgent Raw User-Agent header ('' when absent)
     * @param string $clientIp  Client address as text ('' when unknown)
     * @return VerdictInterface
     */
    public function verify(string $userAgent, string $clientIp): VerdictInterface;
}
