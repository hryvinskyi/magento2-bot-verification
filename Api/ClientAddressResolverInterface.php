<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

/**
 * The address of the client the current request comes from, read past the trusted proxies that forwarded it
 *
 * The forwarding chain is read from the right: the connecting address first, then X-Forwarded-For from its last
 * entry. The shop's own proxies are passed any number of times in a row; after them at most one content delivery
 * network edge server is passed, as the outermost proxy; the next address is the client, whatever it is. Entries
 * further left were written by whoever sent the request and are never read.
 *
 * The framework's remote-address service is not used for this: its answer depends on per-site configuration, it
 * takes the left-most, client-written X-Forwarded-For entry when configured with that header and no trusted proxies,
 * and it trusts exact addresses only, so the ranges of a content delivery network cannot be described to it.
 *
 * @api
 */
interface ClientAddressResolverInterface
{
    /**
     * Address of the current request's client as text
     *
     * @return string|null Null when it cannot be determined: the chain ends before a client is reached, an address
     *                     that has to be read is unreadable, the chain is longer than the resolver follows, the
     *                     configured trusted-proxy ranges cannot be read, or whether the address after the shop's own
     *                     proxies is an edge server cannot be decided because a proxy list has not been fetched or is
     *                     too old
     */
    public function resolve(): ?string;
}
