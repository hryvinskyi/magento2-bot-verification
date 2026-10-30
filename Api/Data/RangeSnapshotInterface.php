<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api\Data;

/**
 * A published address list as last fetched: its merged address ranges per family and the moment it was fetched
 *
 * Immutable. Each family is a string of fixed-width records `start . end` in network byte order - IPV4_RECORD_SIZE
 * bytes each for IPv4, IPV6_RECORD_SIZE for IPv6 - sorted by start, with overlapping and touching ranges merged, so
 * each record is one contiguous block of address space. A family the list holds no range of is an empty string.
 */
interface RangeSnapshotInterface
{
    /**
     * Bytes in one IPv4 record: the range's first address (4 bytes), then its last address (4 bytes), big-endian
     */
    public const IPV4_RECORD_SIZE = 8;

    /**
     * Bytes in one IPv6 record: the range's first address (16 bytes), then its last address (16 bytes), big-endian
     */
    public const IPV6_RECORD_SIZE = 32;

    /**
     * Whether an address lies within one of the ranges
     *
     * An address of any other length than 4 or 16 bytes lies in no range.
     *
     * @param string $address Packed address: 4 bytes (IPv4) or 16 bytes (IPv6), network byte order
     * @return bool
     */
    public function contains(string $address): bool;

    /**
     * Whether the list holds at least one range of the address's family
     *
     * A list speaks only for the families it publishes: one that holds no range of an address's family can say
     * neither that the address is in it nor that it is not. An address of any other length than 4 or 16 bytes
     * belongs to no family the list could hold.
     *
     * @param string $address Packed address: 4 bytes (IPv4) or 16 bytes (IPv6), network byte order
     * @return bool
     */
    public function holdsFamilyOf(string $address): bool;

    /**
     * Whether more than the given number of seconds passed between the fetch and the given moment
     *
     * A snapshot exactly that many seconds old is not older.
     *
     * @param int $seconds Age limit in seconds
     * @param int $now Moment to measure the age at, unix seconds
     * @return bool
     */
    public function isOlderThan(int $seconds, int $now): bool;

    /**
     * Moment the list was fetched
     *
     * @return int Unix seconds, always positive
     */
    public function getFetchedAt(): int;

    /**
     * The IPv4 ranges as packed records, for storage adapters
     *
     * @return string Records of 4-byte start and 4-byte end; '' when the list holds no IPv4 range
     */
    public function getIpv4Ranges(): string;

    /**
     * The IPv6 ranges as packed records, for storage adapters
     *
     * @return string Records of 16-byte start and 16-byte end; '' when the list holds no IPv6 range
     */
    public function getIpv6Ranges(): string;

    /**
     * Number of merged ranges in both families together
     *
     * @return int
     */
    public function countRanges(): int;
}
