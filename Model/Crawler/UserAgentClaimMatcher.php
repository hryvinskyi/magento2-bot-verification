<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Crawler;

use Hryvinskyi\BotVerification\Api\ClaimMatcherInterface;
use Hryvinskyi\BotVerification\Api\CrawlerRegistryInterface;

/**
 * Finds every crawler a User-Agent names, in one regular-expression scan
 *
 * A User-Agent claims every crawler one of whose tokens occurs in it, as a substring, with ASCII letters compared
 * case-insensitively. Where two tokens start at the same position, only the longer one occurs there; a token that
 * starts inside another one's occurrence still occurs, so a User-Agent cannot hide one token by writing it as the
 * tail of another. Every claimed crawler is reported, not only the first: a request is judged by each crawler it
 * claims, and it must not be able to pick its judge by naming a second crawler first.
 *
 * All tokens form one alternation, longest first, inside a lookahead, each alternative marking its crawler's code;
 * the scan tries every position of the User-Agent once, left to right, so a User-Agent that claims nothing costs one
 * pass, as a single match would. The User-Agent is matched byte by byte, not as UTF-8: invalid UTF-8 must not make
 * the match fail and the claim disappear. The pattern is built on first use and kept with the instance; the last
 * User-Agent's claims are kept too, as one request asks about the same User-Agent more than once.
 */
class UserAgentClaimMatcher implements ClaimMatcherInterface
{
    /**
     * The pattern, '' when no crawler is declared; null until it is first needed
     */
    private ?string $pattern = null;

    /**
     * The User-Agent last asked about; null before the first ask
     */
    private ?string $lastUserAgent = null;

    /**
     * @var list<string> Claims of the User-Agent last asked about
     */
    private array $lastClaims = [];

    /**
     * @param CrawlerRegistryInterface $registry Crawlers and their tokens
     */
    public function __construct(
        private readonly CrawlerRegistryInterface $registry
    ) {
    }

    /**
     * @inheritDoc
     */
    public function claims(string $userAgent): array
    {
        if ($userAgent !== $this->lastUserAgent) {
            $this->lastClaims = $this->scan($userAgent);
            $this->lastUserAgent = $userAgent;
        }

        return $this->lastClaims;
    }

    /**
     * Codes of the crawlers whose tokens occur in a User-Agent, in order of first occurrence
     *
     * @param string $userAgent Raw User-Agent header
     * @return list<string>
     */
    private function scan(string $userAgent): array
    {
        if ($userAgent === '') {
            return [];
        }
        $this->pattern ??= $this->buildPattern();
        if ($this->pattern === '') {
            return [];
        }
        $found = preg_match_all($this->pattern, $userAgent, $occurrences, PREG_SET_ORDER);
        if ($found === false || $found === 0) {
            return [];
        }

        $claims = [];
        foreach ($occurrences as $occurrence) {
            $crawlerCode = $occurrence['MARK'] ?? null;
            if (is_string($crawlerCode)) {
                $claims[$crawlerCode] = true;
            }
        }

        return array_keys($claims);
    }

    /**
     * One lookahead over every token, longest first, each marking its crawler's code
     *
     * @return string The pattern; '' when no crawler declares a token
     */
    private function buildPattern(): string
    {
        $alternatives = [];
        foreach ($this->registry->getTokens() as $crawlerCode => $tokens) {
            foreach ($tokens as $token) {
                $alternatives[] = [
                    'length' => strlen($token),
                    'source' => preg_quote($token, '~') . '(*MARK:' . $crawlerCode . ')',
                ];
            }
        }
        if ($alternatives === []) {
            return '';
        }
        usort(
            $alternatives,
            static fn (array $left, array $right): int => $right['length'] <=> $left['length']
        );

        return '~(?=(?:' . implode('|', array_column($alternatives, 'source')) . '))~i';
    }
}
