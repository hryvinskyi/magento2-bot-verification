<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

use Hryvinskyi\BotVerification\Exception\RangeListRejectedException;

/**
 * Fetches a published address list from where its owner publishes it
 *
 * The only part of the module that goes to the network; it is called by the scheduled refresh and the command line,
 * never while a request is being answered.
 */
interface RangeListFetcherInterface
{
    /**
     * Prefixes of the list published at an address
     *
     * @param string $url HTTPS address of the published list
     * @param string $format Code of the list's format (e.g. "prefix_json", "plain_text"); the implementation parses the
     *                       body with the parser registered for that code
     * @return list<string> CIDR prefixes as published
     * @throws RangeListRejectedException when the list cannot be fetched or is not in the expected format, and when
     *                                    no parser is registered for the format: a configuration fault, rejected
     *                                    before anything is fetched, like any other list that cannot be taken, so
     *                                    that one list declared wrongly does not keep the other lists from refreshing
     */
    public function fetch(string $url, string $format): array;
}
