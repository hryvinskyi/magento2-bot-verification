<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Exception;

use Hryvinskyi\BotVerification\Api\Data\RefreshResultInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Phrase;

/**
 * A refresh stopped because a list it had fetched and accepted could not be stored
 *
 * It names that list, chains the storage's exception, and carries what the refresh did with the lists before it -
 * stored or rejected - so a rejection recorded earlier in the same run is reported even though the run failed. The
 * list that could not be stored is in neither part of that result, and the lists after it were not refreshed.
 */
class RangeListStorageException extends LocalizedException
{
    /**
     * @param Phrase $phrase What failed, for people
     * @param string $listCode Code of the list that could not be stored
     * @param RefreshResultInterface $result What the refresh did with the lists before that one
     * @param \Exception|null $cause The storage's exception
     */
    public function __construct(
        Phrase $phrase,
        private readonly string $listCode,
        private readonly RefreshResultInterface $result,
        ?\Exception $cause = null
    ) {
        parent::__construct($phrase, $cause);
    }

    /**
     * Code of the list that could not be stored
     *
     * @return string
     */
    public function getListCode(): string
    {
        return $this->listCode;
    }

    /**
     * What the refresh did with the lists before the one that could not be stored
     *
     * @return RefreshResultInterface
     */
    public function getResult(): RefreshResultInterface
    {
        return $this->result;
    }
}
