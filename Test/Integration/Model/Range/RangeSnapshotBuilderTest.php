<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Model\Range;

use Hryvinskyi\BotVerification\Api\RangeSnapshotBuilderInterface;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\RangeSnapshotBuilder;
use Magento\TestFramework\Helper\Bootstrap;
use PHPUnit\Framework\TestCase;

/**
 * The snapshot builder, as the container builds it, with the generated snapshot factory behind it.
 */
class RangeSnapshotBuilderTest extends TestCase
{
    /**
     * The container's builder turns three prefixes into a snapshot that finds addresses in them
     *
     * @return void
     */
    public function testBuildsASnapshotThroughTheContainer(): void
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilder::class, $builder);

        $snapshot = $builder->build(['203.0.113.0/27', '198.51.100.0/24', '2001:db8:4801::/64'], 1_700_000_000);

        self::assertInstanceOf(RangeSnapshot::class, $snapshot);
        self::assertSame(3, $snapshot->countRanges());
        self::assertSame(1_700_000_000, $snapshot->getFetchedAt());
        self::assertTrue($snapshot->contains(self::packed('203.0.113.31')));
        self::assertTrue($snapshot->contains(self::packed('198.51.100.0')));
        self::assertTrue($snapshot->contains(self::packed('2001:db8:4801::ffff:ffff:ffff:ffff')));
        self::assertFalse($snapshot->contains(self::packed('203.0.113.32')));
        self::assertFalse($snapshot->contains(self::packed('2001:db8:4801:1::')));
    }

    /**
     * Each build creates a new snapshot
     *
     * @return void
     */
    public function testEachBuildCreatesANewSnapshot(): void
    {
        $builder = Bootstrap::getObjectManager()->get(RangeSnapshotBuilderInterface::class);
        self::assertInstanceOf(RangeSnapshotBuilderInterface::class, $builder);

        $first = $builder->build(['192.0.2.0/24'], 1_700_000_000);
        $second = $builder->build(['2001:db8::/32'], 1_700_000_100);

        self::assertNotSame($first, $second);
        self::assertTrue($first->contains(self::packed('192.0.2.1')));
        self::assertFalse($first->contains(self::packed('2001:db8::1')));
        self::assertTrue($second->contains(self::packed('2001:db8::1')));
        self::assertSame(1_700_000_100, $second->getFetchedAt());
    }

    /**
     * Packed form of an address written as text
     *
     * @param string $address Valid address
     * @return string 4 or 16 bytes
     */
    private static function packed(string $address): string
    {
        $packed = inet_pton($address);
        self::assertIsString($packed, $address . ' is not a valid address');

        return $packed;
    }
}
