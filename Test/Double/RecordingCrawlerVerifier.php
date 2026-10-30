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
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Model\Verdict;

/**
 * Records the User-Agent and address of every verification and answers each with a new verdict
 *
 * A verification with an address is answered genuine under the code "recorded", one without as None; every answer is
 * a new object, so a test can tell a kept verdict from a recomputed one by identity.
 */
class RecordingCrawlerVerifier implements CrawlerVerifierInterface
{
    /**
     * @var list<array{userAgent: string, clientIp: string}> Inputs of every verification, in order
     */
    public array $calls = [];

    /**
     * @inheritDoc
     */
    public function verify(string $userAgent, string $clientIp): VerdictInterface
    {
        $this->calls[] = ['userAgent' => $userAgent, 'clientIp' => $clientIp];

        return $clientIp === ''
            ? new Verdict(VerdictStatus::None)
            : new Verdict(VerdictStatus::Genuine, 'recorded');
    }
}
