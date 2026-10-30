<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model\Range;

use Hryvinskyi\BotVerification\Model\Range\RangeSnapshot;
use Hryvinskyi\BotVerification\Model\Range\SnapshotFreshness;
use Hryvinskyi\BotVerification\Test\Double\FixedTimeSource;
use PHPUnit\Framework\TestCase;

/**
 * A snapshot is stale only once it is older than the maximum age; exactly the maximum age is still fresh.
 */
class SnapshotFreshnessTest extends TestCase
{
    private const NOW = 1_700_000_000;
    private const MAX_AGE = 3600;

    /**
     * A snapshot of the given age is stale or fresh
     *
     * @dataProvider ages
     * @param int $age Seconds between the fetch and now
     * @param bool $stale Whether it is too old to accuse
     * @return void
     */
    public function testJudgesTheAgeOfASnapshot(int $age, bool $stale): void
    {
        $freshness = new SnapshotFreshness(new FixedTimeSource(self::NOW), self::MAX_AGE);

        self::assertSame($stale, $freshness->isStale(new RangeSnapshot('', '', self::NOW - $age)));
    }

    /**
     * Ages around the maximum
     *
     * @return array<string, array{int, bool}>
     */
    public static function ages(): array
    {
        return [
            'just fetched' => [0, false],
            'half the maximum age' => [intdiv(self::MAX_AGE, 2), false],
            'exactly the maximum age' => [self::MAX_AGE, false],
            'one second over the maximum age' => [self::MAX_AGE + 1, true],
            'long past the maximum age' => [10 * self::MAX_AGE, true],
        ];
    }

    /**
     * The maximum age is the one given, a week when none is
     *
     * @return void
     */
    public function testReportsTheMaximumAge(): void
    {
        $clock = new FixedTimeSource(self::NOW);

        self::assertSame(self::MAX_AGE, (new SnapshotFreshness($clock, self::MAX_AGE))->getMaxAge());
        self::assertSame(604800, (new SnapshotFreshness($clock))->getMaxAge());
    }

    /**
     * A maximum age that is not positive is refused
     *
     * @dataProvider notPositive
     * @param int $maxAge Maximum age to build with
     * @return void
     */
    public function testRefusesAMaximumAgeThatIsNotPositive(int $maxAge): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new SnapshotFreshness(new FixedTimeSource(self::NOW), $maxAge);
    }

    /**
     * Maximum ages that are not positive
     *
     * @return array<string, array{int}>
     */
    public static function notPositive(): array
    {
        return [
            'zero' => [0],
            'negative' => [-1],
        ];
    }
}
