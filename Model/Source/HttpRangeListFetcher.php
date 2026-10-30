<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Source;

use Hryvinskyi\BotVerification\Api\PrefixListParserInterface;
use Hryvinskyi\BotVerification\Api\RangeListFetcherInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Magento\Framework\HTTP\ClientFactory;

/**
 * Fetches a published address list over HTTPS and reads it with the parser registered for the list's format
 *
 * Parsers are registered by format code in this class's `parsers` argument. This module registers `prefix_json` (the
 * `{"prefixes": [...]}` document) and `plain_text` (one prefix per line); a module whose lists come in a format of its
 * own adds an item:
 *
 * ```xml
 * <type name="Hryvinskyi\BotVerification\Model\Source\HttpRangeListFetcher">
 *     <arguments>
 *         <argument name="parsers" xsi:type="array">
 *             <item name="example_format" xsi:type="object">Vendor\Module\Model\ExampleFormatParser</item>
 *         </argument>
 *     </arguments>
 * </type>
 * ```
 *
 * A format with no parser registered, or registered with something that is not a parser, rejects the list before
 * anything is fetched. It is a configuration fault, but rejected like a list that cannot be fetched, so the other lists
 * still refresh and the fault shows wherever a rejected list does.
 *
 * Every fetch uses a new client and is bounded: 5 seconds to connect and 15 in all, HTTPS only, and a body of at most
 * 2 MiB - enforced by the client when the server announces the size in advance, and checked again on the body
 * received, before it is parsed. Redirects are not followed: a list that moved is rejected like any answer other than
 * 200, and its previous snapshot stays in force until its declaration is corrected. There is no retry; the next
 * scheduled refresh is the retry.
 *
 * The class name is what modules that register a parser configure: renaming or moving it breaks every one of them.
 *
 * @api
 */
class HttpRangeListFetcher implements RangeListFetcherInterface
{
    /**
     * Largest body accepted, in bytes (2 MiB); the largest list known is well under 100 KB
     */
    private const MAX_BODY_BYTES = 2097152;

    private const CONNECT_TIMEOUT_SECONDS = 5;
    private const TOTAL_TIMEOUT_SECONDS = 15;
    private const STATUS_OK = 200;

    /**
     * @param ClientFactory $clientFactory Creates the HTTP client of each fetch
     * @param array<array-key, mixed> $parsers Format code => the parser of that format, as configured
     */
    public function __construct(
        private readonly ClientFactory $clientFactory,
        private readonly array $parsers = []
    ) {
    }

    /**
     * @inheritDoc
     */
    public function fetch(string $url, string $format): array
    {
        $parser = $this->parserOf($format, $url);

        $client = $this->clientFactory->create();
        $client->setTimeout(self::TOTAL_TIMEOUT_SECONDS);
        // One call: the client keeps only the options of its last setOptions().
        $client->setOptions([
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SECONDS,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_MAXFILESIZE => self::MAX_BODY_BYTES,
        ]);
        try {
            $client->get($url);
        } catch (\Exception $exception) {
            // The transport's own description (a name that does not resolve, a timeout, a certificate refused) is
            // the only account of what failed; it reaches the operator through the refresh's report.
            throw new RangeListRejectedException(
                __('The address list at %1 could not be fetched: %2', $url, $exception->getMessage()),
                $exception
            );
        }

        $status = $client->getStatus();
        if ($status !== self::STATUS_OK) {
            throw new RangeListRejectedException(__(
                'The address list at %1 answered with HTTP status %2 instead of 200; redirects are not followed.',
                $url,
                $status
            ));
        }
        $body = $client->getBody();
        if (strlen($body) > self::MAX_BODY_BYTES) {
            throw new RangeListRejectedException(
                __('The address list at %1 is larger than %2 bytes.', $url, self::MAX_BODY_BYTES)
            );
        }

        return $parser->parse($body);
    }

    /**
     * The parser registered for a format
     *
     * @param string $format Code of the format
     * @param string $url Address of the list, for the message
     * @return PrefixListParserInterface
     * @throws RangeListRejectedException When no parser is registered for the format
     */
    private function parserOf(string $format, string $url): PrefixListParserInterface
    {
        $parser = $this->parsers[$format] ?? null;
        if (!$parser instanceof PrefixListParserInterface) {
            throw new RangeListRejectedException(__(
                'No parser is registered for the list format "%1" (%2); this is a configuration fault.',
                $format,
                $url
            ));
        }

        return $parser;
    }
}
