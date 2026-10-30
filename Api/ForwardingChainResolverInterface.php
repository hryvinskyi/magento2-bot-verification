<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * The client at the far end of a forwarding chain: a connecting address and the X-Forwarded-For header it delivered
 *
 * It depends only on the two values, so a chain copied from a web-server log is resolved exactly as a live request.
 *
 * @api
 */
interface ForwardingChainResolverInterface
{
    /**
     * Client address of a forwarding chain, as normalised text; null when it cannot be determined
     *
     * @param string $remoteAddress Connecting address (REMOTE_ADDR)
     * @param string $forwardedFor X-Forwarded-For header as received ('' when absent)
     * @return string|null
     */
    public function resolve(string $remoteAddress, string $forwardedFor): ?string;
}
