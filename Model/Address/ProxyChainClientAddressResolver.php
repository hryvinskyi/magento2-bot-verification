<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Address;

use Hryvinskyi\BotVerification\Api\ClientAddressResolverInterface;
use Hryvinskyi\BotVerification\Api\ForwardingChainResolverInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;

/**
 * The client address of the current request: its connecting address and X-Forwarded-For header, handed to the
 * forwarding-chain walk
 *
 * Only X-Forwarded-For is read. Other forwarding headers hold a single value rather than a chain, so nothing tells
 * which proxy wrote them, and they are not trusted. A request that is not an HTTP request, or has no connecting
 * address, has no client address.
 */
class ProxyChainClientAddressResolver implements ClientAddressResolverInterface
{
    /**
     * @param RequestInterface $request The current request
     * @param ForwardingChainResolverInterface $chainResolver Finds the client of a forwarding chain
     */
    public function __construct(
        private readonly RequestInterface $request,
        private readonly ForwardingChainResolverInterface $chainResolver
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(): ?string
    {
        if (!$this->request instanceof HttpRequest) {
            return null;
        }
        $remoteAddress = $this->request->getServer('REMOTE_ADDR');
        if (!is_string($remoteAddress)) {
            return null;
        }
        $forwardedFor = $this->request->getServer('HTTP_X_FORWARDED_FOR');

        return $this->chainResolver->resolve($remoteAddress, is_string($forwardedFor) ? $forwardedFor : '');
    }
}
