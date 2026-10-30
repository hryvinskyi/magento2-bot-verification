<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Double;

use Hryvinskyi\BotVerification\Api\ForwardingChainResolverInterface;

/**
 * Answers every chain with the address it is given and records the two values of every chain it is asked about
 */
class RecordingForwardingChainResolver implements ForwardingChainResolverInterface
{
    /**
     * @var list<array{remoteAddress: string, forwardedFor: string}> Chains asked about, in order
     */
    public array $calls = [];

    /**
     * @param string|null $address The address to answer with; null for a client that cannot be determined
     */
    public function __construct(
        private readonly ?string $address
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $remoteAddress, string $forwardedFor): ?string
    {
        $this->calls[] = ['remoteAddress' => $remoteAddress, 'forwardedFor' => $forwardedFor];

        return $this->address;
    }
}
