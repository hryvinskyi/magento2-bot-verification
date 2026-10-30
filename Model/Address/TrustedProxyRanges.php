<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Address;

use Hryvinskyi\BotVerification\Api\ConfigInterface;
use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;
use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Api\RangeSnapshotRepositoryInterface;
use Hryvinskyi\BotVerification\Api\SnapshotFreshnessInterface;
use Hryvinskyi\BotVerification\Api\TimeSourceInterface;
use Hryvinskyi\BotVerification\Api\TrustedProxyRangesInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * The shop's own proxies from the configured ranges, and edge servers from the proxy lists modules declare
 *
 * The configured ranges are built once per instance, the first time they are needed, and are asked without any
 * further I/O. An empty configuration is valid and holds no range: nothing configured is trusted. A stored value
 * that is not a list of CIDR ranges - it can only get there around the admin's validation - makes every answer about
 * the shop's own proxies unknown rather than a guess.
 *
 * An edge server is an address that a declared proxy list holds. A stale list still vouches for an address it holds:
 * a range a network once published is very unlikely to belong to a visitor. A list speaks only for the address
 * families it publishes, so one that holds no range of the address's family is passed over, however old it is. An
 * address in no list is known not to be an edge server only when every declared list has been fetched and no list of
 * the address's family is stale; otherwise a new edge server cannot be told from a client, and the answer is
 * unknown. With no proxy list declared nothing is read.
 */
class TrustedProxyRanges implements TrustedProxyRangesInterface
{
    /**
     * Whether the configured ranges have been read
     */
    private bool $isConfigurationRead = false;

    /**
     * Whether the configured ranges could be read; true until they are read
     */
    private bool $isConfigurationReadable = true;

    /**
     * The configured ranges; null when none is configured, or before they are read
     */
    private ?RangeSnapshotInterface $ownProxies = null;

    /**
     * @param ConfigInterface $config The configured ranges of the shop's own proxies
     * @param RangeSnapshotBuilderInterface $builder Turns the configured ranges into a searchable snapshot
     * @param CrawlerRegistryInterface $registry The declared proxy lists, in declaration order
     * @param RangeSnapshotRepositoryInterface $snapshots The last snapshot of each proxy list
     * @param SnapshotFreshnessInterface $freshness Whether a proxy list's snapshot is too old to rule an address out
     * @param TimeSourceInterface $timeSource The moment the configured ranges are read at
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RangeSnapshotBuilderInterface $builder,
        private readonly CrawlerRegistryInterface $registry,
        private readonly RangeSnapshotRepositoryInterface $snapshots,
        private readonly SnapshotFreshnessInterface $freshness,
        private readonly TimeSourceInterface $timeSource
    ) {
    }

    /**
     * @inheritDoc
     */
    public function isOwnProxy(string $address): ?bool
    {
        $this->readConfiguration();
        if (!$this->isConfigurationReadable) {
            return null;
        }

        return $this->ownProxies !== null && $this->ownProxies->contains($address);
    }

    /**
     * @inheritDoc
     */
    public function isEdgeProxy(string $address): ?bool
    {
        $isUndecided = false;
        foreach ($this->registry->getProxyListCodes() as $listCode) {
            $snapshot = $this->snapshots->find($listCode);
            if ($snapshot === null) {
                $isUndecided = true;
                continue;
            }
            if ($snapshot->contains($address)) {
                return true;
            }
            if ($snapshot->holdsFamilyOf($address) && $this->freshness->isStale($snapshot)) {
                $isUndecided = true;
            }
        }

        return $isUndecided ? null : false;
    }

    /**
     * Build the configured ranges, once
     *
     * The builder refuses an empty list, so an empty configuration never reaches it.
     *
     * @return void
     */
    private function readConfiguration(): void
    {
        if ($this->isConfigurationRead) {
            return;
        }
        $this->isConfigurationRead = true;

        $prefixes = $this->config->getTrustedProxies();
        if ($prefixes === []) {
            return;
        }
        try {
            $this->ownProxies = $this->builder->build($prefixes, $this->timeSource->now());
        } catch (RangeListRejectedException) {
            $this->isConfigurationReadable = false;
        }
    }
}
