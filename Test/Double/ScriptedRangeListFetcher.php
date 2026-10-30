<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\RangeListFetcherInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * Answers each published address with what it was scripted to, without the network, and records what it was asked
 */
class ScriptedRangeListFetcher implements RangeListFetcherInterface
{
    /**
     * @var list<array{url: string, format: string}> Fetches asked for, in order
     */
    public array $requests = [];

    /**
     * @param array<string, list<string>|RangeListRejectedException> $answers URL => the prefixes published there, or
     *                                                                       the rejection its fetch ends in
     */
    public function __construct(
        private readonly array $answers
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(string $url, string $format): array
    {
        $this->requests[] = ['url' => $url, 'format' => $format];
        $answer = $this->answers[$url] ?? null;
        if ($answer === null) {
            throw new \LogicException(sprintf('No answer is scripted for %s.', $url));
        }
        if ($answer instanceof RangeListRejectedException) {
            throw $answer;
        }

        return $answer;
    }
}
