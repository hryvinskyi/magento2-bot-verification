<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\ClientAddressResolverInterface;

/**
 * Answers with the address it is given and counts how often it is asked
 */
class CountingClientAddressResolver implements ClientAddressResolverInterface
{
    /**
     * Number of times the address was asked for
     */
    public int $calls = 0;

    /**
     * @param string|null $address The address to answer with; null for an address that cannot be determined
     */
    public function __construct(
        public ?string $address = null
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(): ?string
    {
        ++$this->calls;

        return $this->address;
    }
}
