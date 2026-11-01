<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Address;

use Hryvinskyi\BotVerification\Api\AddressParserInterface;
use Hryvinskyi\BotVerification\Api\ForwardingChainResolverInterface;
use Hryvinskyi\BotVerification\Api\TrustedProxyRangesInterface;

/**
 * Finds the client of a forwarding chain by walking it from the right, past the proxies that may be trusted
 *
 * The connecting address is the only one a client cannot forge; every header is text the client may send. Each proxy
 * appends the address it received the request from to X-Forwarded-For, so the right-most entries were written by
 * the proxies in front of the shop and the left-most by whoever sent the request. The chain is therefore the
 * connecting address followed by the X-Forwarded-For entries from right to left, and the walk:
 * 1. starts at the connecting address;
 * 2. while the address is one of the shop's own proxies, moves to the next entry to the left - any number of them;
 * 3. if the address reached is a content delivery network's edge server, moves once more to the left - once only;
 * 4. returns the address reached: the client, without asking whether it is a proxy. Entries further left are never
 *    read.
 *
 * Only one edge server is passed because a network's published ranges hold more than the servers that forward
 * visitors: its customers run code there that can send a request with any X-Forwarded-For through the network to
 * this shop, and the network appends that code's address - an address inside its own ranges. Passing it as well would
 * read the header that code wrote.
 *
 * The entry left of the one edge server is the address the network received the request from only if the shop's
 * origin accepts connections from the network's address space solely through the network's own proxy, for instance
 * by requiring the network's client certificate on every connection to the origin. Where the origin can be reached
 * around the network, code running inside it that connects straight to the origin is taken for the edge server, and
 * the entry that code wrote is read as the client: an impostor passes as whatever address it chose, and no real
 * crawler is refused because of it.
 *
 * On a site without any proxy the connecting address is the visitor's, and a forged X-Forwarded-For is never read.
 *
 * No client is found - null - when the chain ends before one is reached (only the shop's own proxies, or an edge
 * server with nothing to its left), when an address that has to be read is not an address (`unknown`, a port suffix,
 * brackets, a zone index, an empty entry), when more than MAX_ADDRESSES addresses would have to be read, when the
 * configured ranges cannot be read, or when whether the address after the shop's own proxies is an edge server
 * cannot be decided.
 *
 * The last result is kept with the two values it was computed for and served again only for the same pair.
 */
class ForwardingChainResolver implements ForwardingChainResolverInterface
{
    /**
     * Most addresses the walk reads, the connecting address and the client included
     */
    public const MAX_ADDRESSES = 20;

    /**
     * The connecting address and header the kept result was computed for; null before the first result
     */
    private ?string $resultKey = null;

    /**
     * The result computed last
     */
    private ?string $result = null;

    /**
     * @param AddressParserInterface $addressParser Reads each address of the chain
     * @param TrustedProxyRangesInterface $trustedProxies Whether an address is a proxy that may be passed
     */
    public function __construct(
        private readonly AddressParserInterface $addressParser,
        private readonly TrustedProxyRangesInterface $trustedProxies
    ) {
    }

    /**
     * @inheritDoc
     */
    public function resolve(string $remoteAddress, string $forwardedFor): ?string
    {
        $key = $remoteAddress . "\n" . $forwardedFor;
        if ($this->resultKey !== $key) {
            $this->result = $this->walk($this->chain($remoteAddress, $forwardedFor));
            $this->resultKey = $key;
        }

        return $this->result;
    }

    /**
     * The client of a chain read from the right
     *
     * @param list<string> $chain Addresses as text, the connecting address first, at most MAX_ADDRESSES of them
     * @return string|null
     */
    private function walk(array $chain): ?string
    {
        $reached = $this->passOwnProxies($chain);
        if ($reached === null) {
            return null;
        }
        $isEdgeProxy = $this->trustedProxies->isEdgeProxy($reached['address']);
        if ($isEdgeProxy === null) {
            return null;
        }
        if (!$isEdgeProxy) {
            return $this->toText($reached['address']);
        }

        $client = $this->addressParser->toBinary($chain[$reached['position'] + 1] ?? '');

        return $client === null ? null : $this->toText($client);
    }

    /**
     * The first address of the chain that is not one of the shop's own proxies
     *
     * @param list<string> $chain Addresses as text, the connecting address first
     * @return array{position: int, address: string}|null Its position in the chain and its packed form; null when
     *                                                    the chain ends first, an address is unreadable, or the
     *                                                    configured ranges cannot be read
     */
    private function passOwnProxies(array $chain): ?array
    {
        foreach ($chain as $position => $text) {
            $address = $this->addressParser->toBinary($text);
            if ($address === null) {
                return null;
            }
            $isOwnProxy = $this->trustedProxies->isOwnProxy($address);
            if ($isOwnProxy === null) {
                return null;
            }
            if (!$isOwnProxy) {
                return ['position' => $position, 'address' => $address];
            }
        }

        return null;
    }

    /**
     * The connecting address followed by the right-most X-Forwarded-For entries, right to left, trimmed
     *
     * Entries the walk could never reach are dropped before they are trimmed: everything left of the client was
     * written by the client, and a header can be as long as the web server allows.
     *
     * @param string $remoteAddress Connecting address
     * @param string $forwardedFor X-Forwarded-For header; '' when absent
     * @return list<string> At most MAX_ADDRESSES addresses as text
     */
    private function chain(string $remoteAddress, string $forwardedFor): array
    {
        if ($forwardedFor === '') {
            return [$remoteAddress];
        }
        $entries = array_slice(explode(',', $forwardedFor), -(self::MAX_ADDRESSES - 1));

        return [$remoteAddress, ...array_map('trim', array_reverse($entries))];
    }

    /**
     * A packed address as normalised text
     *
     * @param string $address Packed address, 4 or 16 bytes
     * @return string|null
     */
    private function toText(string $address): ?string
    {
        $text = inet_ntop($address);

        return $text === false ? null : $text;
    }
}
