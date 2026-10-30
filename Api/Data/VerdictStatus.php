<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api\Data;

/**
 * What a request's claim to be a known crawler turns out to be, judged against the addresses the crawler's owner
 * publishes
 *
 * - None: the User-Agent names no known crawler, so there is nothing to judge.
 * - Genuine: the client address lies within one of the published address lists of the claimed crawler, however long
 *   ago that list was last fetched.
 * - Impostor: the client address is a valid public address, every published list of the claimed crawler has been
 *   fetched, at least one of them publishes ranges of the address's family (IPv4 or IPv6), none of those is older
 *   than the maximum age, and the address lies in none of them.
 * - Unverifiable: every other claim - a list that was never fetched, a list of the address's family older than the
 *   maximum age, no list of that family at all, or a client address that is private, loopback, reserved or
 *   unreadable. Missing data can neither confirm nor accuse a crawler, so an unverifiable request is never refused.
 *
 * @api
 */
enum VerdictStatus: string
{
    case None = 'none';
    case Genuine = 'genuine';
    case Impostor = 'impostor';
    case Unverifiable = 'unverifiable';
}
