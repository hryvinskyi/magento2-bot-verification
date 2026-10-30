<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Model;

use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Model\Verdict;
use PHPUnit\Framework\TestCase;

/**
 * A verdict names a crawler exactly when its status is not None, and answers whether it is genuine or an impostor.
 */
class VerdictTest extends TestCase
{
    /**
     * A valid combination keeps the status and the code exactly as given
     *
     * @dataProvider validCombinations
     * @param VerdictStatus $status Status to build with
     * @param string|null $crawlerCode Code to build with
     * @return void
     */
    public function testKeepsAValidCombination(VerdictStatus $status, ?string $crawlerCode): void
    {
        $verdict = new Verdict($status, $crawlerCode);

        self::assertSame($status, $verdict->getStatus());
        self::assertSame($crawlerCode, $verdict->getCrawlerCode());
    }

    /**
     * Every status with the code it may carry
     *
     * @return array<string, array{VerdictStatus, string|null}>
     */
    public static function validCombinations(): array
    {
        return [
            'none without a code' => [VerdictStatus::None, null],
            'genuine with a code' => [VerdictStatus::Genuine, 'examplebot'],
            'impostor with a code' => [VerdictStatus::Impostor, 'examplebot'],
            'unverifiable with a code' => [VerdictStatus::Unverifiable, 'otherbot'],
        ];
    }

    /**
     * A None verdict built without a code argument names no crawler
     *
     * @return void
     */
    public function testNoneWithoutACodeArgumentNamesNoCrawler(): void
    {
        $verdict = new Verdict(VerdictStatus::None);

        self::assertSame(VerdictStatus::None, $verdict->getStatus());
        self::assertNull($verdict->getCrawlerCode());
    }

    /**
     * A None verdict that names a crawler, or any other verdict that names none, is refused
     *
     * @dataProvider refusedCombinations
     * @param VerdictStatus $status Status to build with
     * @param string|null $crawlerCode Code to build with
     * @return void
     */
    public function testRefusesAnInvalidCombination(VerdictStatus $status, ?string $crawlerCode): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Verdict($status, $crawlerCode);
    }

    /**
     * Every status with a code it may not carry; an empty code counts as no code
     *
     * @return array<string, array{VerdictStatus, string|null}>
     */
    public static function refusedCombinations(): array
    {
        return [
            'none with a code' => [VerdictStatus::None, 'examplebot'],
            'none with an empty code' => [VerdictStatus::None, ''],
            'genuine without a code' => [VerdictStatus::Genuine, null],
            'genuine with an empty code' => [VerdictStatus::Genuine, ''],
            'impostor without a code' => [VerdictStatus::Impostor, null],
            'impostor with an empty code' => [VerdictStatus::Impostor, ''],
            'unverifiable without a code' => [VerdictStatus::Unverifiable, null],
            'unverifiable with an empty code' => [VerdictStatus::Unverifiable, ''],
        ];
    }

    /**
     * Genuine and impostor are answered from the status alone
     *
     * @dataProvider statusQuestions
     * @param VerdictStatus $status Status to build with
     * @param string|null $crawlerCode Code the status requires
     * @param bool $genuine Expected answer to isGenuine()
     * @param bool $impostor Expected answer to isImpostor()
     * @return void
     */
    public function testAnswersFromTheStatus(
        VerdictStatus $status,
        ?string $crawlerCode,
        bool $genuine,
        bool $impostor
    ): void {
        $verdict = new Verdict($status, $crawlerCode);

        self::assertSame($genuine, $verdict->isGenuine());
        self::assertSame($impostor, $verdict->isImpostor());
    }

    /**
     * Each status with the answers it gives
     *
     * @return array<string, array{VerdictStatus, string|null, bool, bool}>
     */
    public static function statusQuestions(): array
    {
        return [
            'none' => [VerdictStatus::None, null, false, false],
            'genuine' => [VerdictStatus::Genuine, 'examplebot', true, false],
            'impostor' => [VerdictStatus::Impostor, 'examplebot', false, true],
            'unverifiable' => [VerdictStatus::Unverifiable, 'examplebot', false, false],
        ];
    }
}
