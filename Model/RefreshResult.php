<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;

/**
 * Immutable result of one refresh, valid by construction: a list given as both refreshed and rejected is refused
 */
class RefreshResult implements RefreshResultInterface
{
    /**
     * @param array<string, int> $refreshed List code => ranges stored
     * @param array<string, string> $rejected List code => why the list was rejected
     * @throws \InvalidArgumentException When a list is given as both refreshed and rejected
     */
    public function __construct(
        private readonly array $refreshed = [],
        private readonly array $rejected = []
    ) {
        $both = array_intersect_key($refreshed, $rejected);
        if ($both !== []) {
            throw new \InvalidArgumentException(sprintf(
                'A list cannot be both refreshed and rejected: %s.',
                implode(', ', array_keys($both))
            ));
        }
    }

    /**
     * @inheritDoc
     */
    public function getRefreshed(): array
    {
        return $this->refreshed;
    }

    /**
     * @inheritDoc
     */
    public function getRejected(): array
    {
        return $this->rejected;
    }
}
