<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

use Hryvinskyi\BotVerification\Api\Data\RangeSnapshotInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * Turns the prefixes of a published address list into a snapshot
 *
 * The list is taken whole or not at all: one malformed prefix rejects it, so a snapshot never holds part of a list.
 */
interface RangeSnapshotBuilderInterface
{
    /**
     * Snapshot of the given prefixes, sorted and merged per address family
     *
     * Overlapping and touching ranges become one, so the snapshot counts the contiguous blocks of address space the
     * list covers, however its publisher splits them. An IPv6 prefix wholly inside the space of IPv6 addresses that
     * carry an IPv4 address is an IPv4 range.
     *
     * @param list<string> $prefixes CIDR prefixes, e.g. '203.0.113.0/27', '2001:db8:4801:10::/64'
     * @param int $fetchedAt Moment the list was fetched, unix seconds, positive
     * @return RangeSnapshotInterface
     * @throws RangeListRejectedException when the list is empty or any prefix is malformed
     */
    public function build(array $prefixes, int $fetchedAt): RangeSnapshotInterface;
}
