<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api\Data;

/**
 * The verdict on one request's claim to be a known crawler
 *
 * Immutable. It names a crawler exactly when its status is not None, and never with an empty code.
 *
 * @api
 */
interface VerdictInterface
{
    /**
     * What the claim turned out to be
     *
     * @return VerdictStatus
     */
    public function getStatus(): VerdictStatus;

    /**
     * Code of the claimed crawler the verdict is about
     *
     * For an impostor, the first claimed crawler that accuses the address; for an unverifiable verdict, the first
     * claimed crawler that cannot vouch for it; for a genuine one, the first claimed crawler.
     *
     * @return string|null Null exactly when the status is None
     */
    public function getCrawlerCode(): ?string;

    /**
     * Whether the request comes from the addresses of the crawler it claims to be
     *
     * @return bool True exactly when the status is Genuine
     */
    public function isGenuine(): bool;

    /**
     * Whether the request claims to be a crawler whose current published addresses do not include it
     *
     * @return bool True exactly when the status is Impostor
     */
    public function isImpostor(): bool;
}
