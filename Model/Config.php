<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Model;

use Hryvinskyi\BotVerification\Api\ConfigInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;

/**
 * The module's settings, read from the stored system configuration
 *
 * The trusted proxies describe the infrastructure in front of the whole installation, not a store, so they are read
 * at the default scope only. The refusal switch is read for the current store, so a shop can turn it on for some
 * stores first.
 */
class Config implements ConfigInterface
{
    public const XML_PATH_TRUSTED_PROXIES = 'hryvinskyi_bot_verification/client_address/trusted_proxies';
    private const XML_PATH_REFUSE_IMPOSTORS = 'hryvinskyi_bot_verification/impostor/refuse';

    /**
     * @param ScopeConfigInterface $scopeConfig Stored system configuration
     */
    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig
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

        $lines = preg_split('/\R/', $value);

        return array_values(array_filter(
            array_map('trim', $lines === false ? [] : $lines),
            static fn (string $line): bool => $line !== ''
        ));
    }

    /**
     * @inheritDoc
     */
    public function isImpostorRefusalEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(self::XML_PATH_REFUSE_IMPOSTORS, ScopeInterface::SCOPE_STORE);
    }
}
