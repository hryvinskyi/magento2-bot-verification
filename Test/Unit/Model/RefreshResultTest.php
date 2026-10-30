<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model;

use Hryvinskyi\BotVerification\Model\RefreshResult;
use PHPUnit\Framework\TestCase;

/**
 * A refresh result keeps what it is given and refuses a list reported as both refreshed and rejected.
 */
class RefreshResultTest extends TestCase
{
    /**
     * The refreshed and the rejected lists come back as given, in order
     *
     * @return void
     */
    public function testKeepsBothLists(): void
    {
        $result = new RefreshResult(
            ['example_main' => 12, 'example_edge' => 3],
            ['example_extra' => 'The list could not be fetched.']
        );

        self::assertSame(['example_main' => 12, 'example_edge' => 3], $result->getRefreshed());
        self::assertSame(['example_extra' => 'The list could not be fetched.'], $result->getRejected());
    }

    /**
     * A result given nothing reports nothing
     *
     * @return void
     */
    public function testIsEmptyByDefault(): void
    {
        $result = new RefreshResult();

        self::assertSame([], $result->getRefreshed());
        self::assertSame([], $result->getRejected());
    }

    /**
     * A list cannot be both refreshed and rejected
     *
     * @return void
     */
    public function testRefusesAListThatIsBothRefreshedAndRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('A list cannot be both refreshed and rejected: example_main.');

        new RefreshResult(['example_main' => 12], ['example_main' => 'The list could not be fetched.']);
    }
}
