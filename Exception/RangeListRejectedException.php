<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Exception;

use Magento\Framework\Exception\LocalizedException;

/**
 * A published address list could not be fetched, or what was fetched is not a list the module can take whole.
 */
class RangeListRejectedException extends LocalizedException
{
}
