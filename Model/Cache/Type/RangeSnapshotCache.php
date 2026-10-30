<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model\Cache\Type;

use Magento\Framework\App\Cache\Type\FrontendPool;
use Magento\Framework\Cache\Frontend\Decorator\TagScope;

/**
 * Cache type of the published address ranges, tagging everything it stores so that cleaning the type removes only it
 *
 * Like every new cache type it is off until enabled; while it is off, or after it is disabled, every read goes to the
 * durable storage.
 */
class RangeSnapshotCache extends TagScope
{
    /**
     * Cache type code, unique among all cache types
     */
    public const TYPE_IDENTIFIER = 'bot_verification';

    /**
     * Tag of every entry of this cache type
     */
    public const CACHE_TAG = 'BOT_VERIFICATION';

    /**
     * @param FrontendPool $cacheFrontendPool Cache frontends by cache type, honouring whether a type is enabled
     */
    public function __construct(FrontendPool $cacheFrontendPool)
    {
        parent::__construct($cacheFrontendPool->get(self::TYPE_IDENTIFIER), self::CACHE_TAG);
    }
}
