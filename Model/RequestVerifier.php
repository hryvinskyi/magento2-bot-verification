<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\ClaimMatcherInterface;
use Hryvinskyi\BotVerification\Api\ClientAddressResolverInterface;
use Hryvinskyi\BotVerification\Api\CrawlerVerifierInterface;
use Hryvinskyi\BotVerification\Api\Data\VerdictInterface;
use Hryvinskyi\BotVerification\Api\RequestVerifierInterface;
use Magento\Framework\HTTP\Header;

/**
 * The verdict on the current HTTP request, from its raw User-Agent and its client address
 *
 * The client address is resolved only for a User-Agent that claims a crawler: a request that claims none pays
 * nothing for it, and is judged with no address, which a None verdict never reads.
 *
 * The verdict is kept with the User-Agent and the address it was computed for, and served again only for the same
 * pair, so a shared instance never answers one request with another request's verdict.
 */
class RequestVerifier implements RequestVerifierInterface
{
    /**
     * User-Agent and client address the kept verdict was computed for; null before the first verdict
     */
    private ?string $verdictKey = null;

    /**
     * The verdict computed last; null before the first one
     */
    private ?VerdictInterface $verdict = null;

    /**
     * @param Header $header Headers of the current request
     * @param ClaimMatcherInterface $claimMatcher Crawlers a User-Agent claims
     * @param ClientAddressResolverInterface $clientAddressResolver Address of the current request's client
     * @param CrawlerVerifierInterface $crawlerVerifier Judges the claim
     */
    public function __construct(
        private readonly Header $header,
        private readonly ClaimMatcherInterface $claimMatcher,
        private readonly ClientAddressResolverInterface $clientAddressResolver,
        private readonly CrawlerVerifierInterface $crawlerVerifier
    ) {
    }

    /**
     * @inheritDoc
     */
    public function verify(): VerdictInterface
    {
        $userAgent = (string) $this->header->getHttpUserAgent(false);
        $clientAddress = $this->claimMatcher->claims($userAgent) === []
            ? ''
            : ($this->clientAddressResolver->resolve() ?? '');

        $key = $userAgent . "\n" . $clientAddress;
        if ($this->verdict === null || $this->verdictKey !== $key) {
            $this->verdict = $this->crawlerVerifier->verify($userAgent, $clientAddress);
            $this->verdictKey = $key;
        }

        return $this->verdict;
    }
}
