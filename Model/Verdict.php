<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;

/**
 * Immutable verdict, valid by construction
 *
 * A None verdict names no crawler, and every other verdict names one: a None verdict with any code (an empty one
 * included) and any other verdict without a code, or with an empty one, are refused.
 *
 * Every caller passes the status. Production builds objects through the compiled object manager, which takes a
 * passed argument only when it is set and otherwise falls back to what the compilation recorded for the parameter;
 * for an enum-typed parameter that record is a shared instance of the enum, which cannot be instantiated.
 */
class Verdict implements VerdictInterface
{
    /**
     * @param VerdictStatus $status What the claim turned out to be
     * @param string|null $crawlerCode Code of the crawler the verdict is about; null for a None verdict
     * @throws \InvalidArgumentException When the code is present on a None verdict or missing on any other
     */
    public function __construct(
        private readonly VerdictStatus $status,
        private readonly ?string $crawlerCode = null
    ) {
        if ($status === VerdictStatus::None && $crawlerCode !== null) {
            throw new \InvalidArgumentException(
                'A verdict on a request that claims no known crawler cannot name a crawler.'
            );
        }
        if ($status !== VerdictStatus::None && ($crawlerCode === null || $crawlerCode === '')) {
            throw new \InvalidArgumentException(
                sprintf('A "%s" verdict must name the crawler it is about.', $status->value)
            );
        }
    }

    /**
     * @inheritDoc
     */
    public function getStatus(): VerdictStatus
    {
        return $this->status;
    }

    /**
     * @inheritDoc
     */
    public function getCrawlerCode(): ?string
    {
        return $this->crawlerCode;
    }

    /**
     * @inheritDoc
     */
    public function isGenuine(): bool
    {
        return $this->status === VerdictStatus::Genuine;
    }

    /**
     * @inheritDoc
     */
    public function isImpostor(): bool
    {
        return $this->status === VerdictStatus::Impostor;
    }
}
