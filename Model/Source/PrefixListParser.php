<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Source;

use Hryvinskyi\BotVerification\Api\PrefixListParserInterface;
use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;
use Magento\Framework\Serialize\SerializerInterface;

/**
 * Reads the JSON document most crawler owners publish: `{"prefixes": [{"ipv4Prefix": "…"}, {"ipv6Prefix": "…"}]}`
 *
 * Each entry of `prefixes` is an object holding its prefix as text under `ipv4Prefix` or `ipv6Prefix` (an entry
 * holding both gives both). Other keys of the document (a creation time, a sync token, notes) and of an entry are
 * ignored. Anything else is rejected whole, saying what was wrong: a body that is not JSON, a document without a
 * `prefixes` list, an entry that is not an object, holds neither key or holds a value that is not text, and a list
 * without entries.
 */
class PrefixListParser implements PrefixListParserInterface
{
    private const KEY_PREFIXES = 'prefixes';

    /**
     * Keys an entry holds its prefix under
     */
    private const PREFIX_KEYS = ['ipv4Prefix', 'ipv6Prefix'];

    /**
     * @param SerializerInterface $serializer Decodes JSON
     */
    public function __construct(
        private readonly SerializerInterface $serializer
    ) {
    }

    /**
     * @inheritDoc
     */
    public function parse(string $body): array
    {
        $entries = $this->readEntries($body);
        if ($entries === []) {
            throw new RangeListRejectedException(__('The published address list holds no prefixes.'));
        }

        $prefixes = [];
        foreach ($entries as $index => $entry) {
            foreach ($this->readEntry($index + 1, $entry) as $prefix) {
                $prefixes[] = $prefix;
            }
        }

        return $prefixes;
    }

    /**
     * The entries of the document's `prefixes` list
     *
     * @param string $body The document as fetched
     * @return list<mixed>
     * @throws RangeListRejectedException When the body is not JSON or holds no `prefixes` list
     */
    private function readEntries(string $body): array
    {
        try {
            $document = $this->serializer->unserialize($body);
        } catch (\InvalidArgumentException $exception) {
            throw new RangeListRejectedException(__('The published address list is not valid JSON.'), $exception);
        }
        if (!is_array($document) || !array_key_exists(self::KEY_PREFIXES, $document)) {
            throw new RangeListRejectedException(
                __('The published address list is not a JSON object with a "prefixes" entry.')
            );
        }
        $entries = $document[self::KEY_PREFIXES];
        if (!is_array($entries) || !array_is_list($entries)) {
            throw new RangeListRejectedException(
                __('The "prefixes" entry of the published address list is not a list.')
            );
        }

        return $entries;
    }

    /**
     * The prefixes one entry holds
     *
     * @param int $position Position of the entry in the list, counted from 1, for the message
     * @param mixed $entry The entry as decoded
     * @return list<string> At least one prefix
     * @throws RangeListRejectedException When the entry is not an object, holds no prefix key or a value that is not
     *                                    text
     */
    private function readEntry(int $position, mixed $entry): array
    {
        if (!is_array($entry)) {
            throw new RangeListRejectedException(
                __('Entry %1 of the published address list is not an object.', $position)
            );
        }

        $prefixes = [];
        foreach (self::PREFIX_KEYS as $key) {
            if (!array_key_exists($key, $entry)) {
                continue;
            }
            $prefix = $entry[$key];
            if (!is_string($prefix)) {
                throw new RangeListRejectedException(__(
                    'Entry %1 of the published address list holds a value under "%2" that is not text.',
                    $position,
                    $key
                ));
            }
            $prefixes[] = $prefix;
        }
        if ($prefixes === []) {
            throw new RangeListRejectedException(__(
                'Entry %1 of the published address list holds neither an "ipv4Prefix" nor an "ipv6Prefix".',
                $position
            ));
        }

        return $prefixes;
    }
}
