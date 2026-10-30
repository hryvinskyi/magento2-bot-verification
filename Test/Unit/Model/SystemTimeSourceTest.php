<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model;

use Hryvinskyi\BotVerification\Model\SystemTimeSource;
use PHPUnit\Framework\TestCase;

/**
 * The system time source tells the current moment in unix seconds.
 */
class SystemTimeSourceTest extends TestCase
{
    /**
     * The moment told lies between the moments read just before and just after asking
     *
     * @return void
     */
    public function testTellsTheCurrentMoment(): void
    {
        $before = time();
        $now = (new SystemTimeSource())->now();
        $after = time();

        self::assertGreaterThanOrEqual($before, $now);
        self::assertLessThanOrEqual($after, $now);
    }
}
