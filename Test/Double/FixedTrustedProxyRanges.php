<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\TrustedProxyRangesInterface;

/**
 * Answers from two fixed sets of ranges - the shop's own proxies and edge servers - records every address it is asked
 * about, as text, and can be told to answer that it cannot decide
 */
class FixedTrustedProxyRanges implements TrustedProxyRangesInterface
{
    /**
     * @var list<string> Addresses asked about as own proxies, as text, in order
     */
    public array $ownAsked = [];

    /**
     * @var list<string> Addresses asked about as edge servers, as text, in order
     */
    public array $edgeAsked = [];

    /**
     * Whether every question about the shop's own proxies is answered with null
     */
    public bool $ownUndecidable = false;

    /**
     * Whether every question about edge servers is answered with null
     */
    public bool $edgeUndecidable = false;

    /**
     * @param RangeSnapshotInterface $ownProxies Ranges of the shop's own proxies
     * @param RangeSnapshotInterface $edgeProxies Ranges of the edge servers
     */
    public function __construct(
        private readonly RangeSnapshotInterface $ownProxies,
        private readonly RangeSnapshotInterface $edgeProxies
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isOwnProxy(string $address): ?bool
    {
        $this->ownAsked[] = (string) inet_ntop($address);

        return $this->ownUndecidable ? null : $this->ownProxies->contains($address);
    }

    /**
     * @inheritDoc
     */
    public function isEdgeProxy(string $address): ?bool
    {
        $this->edgeAsked[] = (string) inet_ntop($address);

        return $this->edgeUndecidable ? null : $this->edgeProxies->contains($address);
    }
}
