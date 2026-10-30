<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Api\RangeListRefresherInterface;
use Magento\Framework\Exception\LocalizedException;

/**
 * Answers every refresh with the result it was scripted to, or ends it in the exception it was scripted to, without
 * fetching anything, and records what it was asked
 */
class ScriptedRangeListRefresher implements RangeListRefresherInterface
{
    /**
     * @var list<array{listCodes: list<string>, acceptShrink: bool}> Refreshes asked for, in order
     */
    public array $calls = [];

    /**
     * @param RefreshResultInterface|LocalizedException $answer The result every refresh returns, or the exception
     *                                                          it ends in
     */
    public function __construct(
        private readonly RefreshResultInterface|LocalizedException $answer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function refresh(array $listCodes = [], bool $acceptShrink = false): RefreshResultInterface
    {
        $this->calls[] = ['listCodes' => $listCodes, 'acceptShrink' => $acceptShrink];
        if ($this->answer instanceof LocalizedException) {
            throw $this->answer;
        }

        return $this->answer;
    }
}
