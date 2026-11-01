<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Api;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Hryvinskyi\BotVerification\Exception\RangeListStorageException;
use Magento\Framework\Exception\LocalizedException;

/**
 * Brings the stored snapshots of the published address lists up to date
 *
 * It goes to the network, so it is run by the scheduled refresh and the command line, never while a request is being
 * answered. A list that cannot be refreshed keeps its previous snapshot.
 *
 * @api
 */
interface RangeListRefresherInterface
{
    /**
     * Fetch the given lists and store each one that is fetched and read whole
     *
     * A list that is rejected is reported with the reason and the next list is refreshed. A list that would cover
     * fewer than half of the stored snapshot's addresses in any address family that snapshot covers is rejected the
     * same way, unless the shrink is accepted. A failure to store a list is not a rejection: it ends the refresh with
     * a RangeListStorageException that names the list, chains the storage's exception and carries what the refresh
     * did with the lists before it, so a rejection recorded earlier is not lost.
     *
     * @param list<string> $listCodes Lists to refresh; empty = every list the registry declares
     * @param bool $acceptShrink Store a list even when it covers fewer than half of the stored snapshot's addresses
     *                           in one of that snapshot's families
     * @return RefreshResultInterface The ranges stored per refreshed list and the reason per rejected list
     * @throws RangeListStorageException when a list cannot be stored (the lists after it are not refreshed)
     * @throws LocalizedException when a requested code is not declared (nothing is fetched)
     */
    public function refresh(array $listCodes = [], bool $acceptShrink = false): RefreshResultInterface;
}
