<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\CrawlerVerifierInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;

/**
 * Answers every verification with the verdict it is given and records the User-Agent and address it was asked about
 */
class FixedCrawlerVerifier implements CrawlerVerifierInterface
{
    /**
     * @var list<array{userAgent: string, clientIp: string}> Inputs of every verification, in order
     */
    public array $calls = [];

    /**
     * @param VerdictInterface $verdict The verdict every verification gets
     */
    public function __construct(
        private readonly VerdictInterface $verdict
    ) {
    }

    /**
     * @inheritDoc
     */
    public function verify(string $userAgent, string $clientIp): VerdictInterface
    {
        $this->calls[] = ['userAgent' => $userAgent, 'clientIp' => $clientIp];

        return $this->verdict;
    }
}
