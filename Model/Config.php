<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\ConfigInterface;
use Hryvinskyi\BotVerification\Api\LineListReaderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's settings, read from the stored system configuration
 *
 * The trusted proxies describe the infrastructure in front of the whole installation, not a store, so they are read
 * at the default scope only. The refusal switch is read for the store the request has been resolved to when the
 * switch is read, which is before routing, so a shop can turn it on for some stores first; which store that is for
 * each kind of request is stated on the interface.
 */
class Config implements ConfigInterface
{
    public const XML_PATH_TRUSTED_PROXIES = 'hryvinskyi_bot_verification/client_address/trusted_proxies';
    private const XML_PATH_REFUSE_IMPOSTORS = 'hryvinskyi_bot_verification/impostor/refuse';

    /**
     * @param ScopeConfigInterface $scopeConfig Stored system configuration
     * @param LineListReaderInterface $lineListReader Reads the multi-line trusted-proxies value the way it is stored
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly LineListReaderInterface $lineListReader
    ) {
    }

    /**
     * @inheritDoc
     */
    public function getTrustedProxies(): array
    {
        $value = $this->scopeConfig->getValue(
            self::XML_PATH_TRUSTED_PROXIES,
            ScopeConfigInterface::SCOPE_TYPE_DEFAULT
        );
        if (!is_string($value)) {
            return [];
        }

        return $this->lineListReader->read($value);
    }

    /**
     * @inheritDoc
     */
    public function isImpostorRefusalEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REFUSE_IMPOSTORS, ScopeInterface::SCOPE_STORE);
    }
}
