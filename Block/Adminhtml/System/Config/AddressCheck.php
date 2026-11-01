<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Block\Adminhtml\System\Config;

use Hryvinskyi\BotVerification\Api\ClientAddressResolverInterface;
use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Escaper;
use Magento\Framework\HTTP\PhpEnvironment\Request as HttpRequest;
use Magento\Framework\Phrase;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * A configuration row that shows the admin's own request as the shop sees it: the connecting address, the
 * X-Forwarded-For header, and the visitor address resolved from them
 *
 * It only reads and prints; the resolution is the client-address resolver's. Every value is escaped, since the header
 * is text whoever sent the request wrote. The row saves no value and is rendered for each admin page view, never
 * cached.
 */
class AddressCheck extends Field
{
    /**
     * Shown in place of a value the request does not carry
     */
    private const ABSENT = '—';

    /**
     * @param Context $context Block context; its request is the one shown
     * @param ClientAddressResolverInterface $clientAddressResolver Resolves the visitor address of the same request
     * @param Escaper $escaper Escapes each line for HTML
     * @param array<string, mixed> $data Block data
     * @param SecureHtmlRenderer|null $secureRenderer Renders the row's inline script and style tags
     */
    public function __construct(
        Context $context,
        private readonly ClientAddressResolverInterface $clientAddressResolver,
        private readonly Escaper $escaper,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * The three lines of the check, each escaped
     *
     * @param AbstractElement $element The configuration field the row belongs to
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        $lines = [
            __('Connection from: %1', $this->serverValue('REMOTE_ADDR') ?? self::ABSENT),
            __('X-Forwarded-For: %1', $this->serverValue('HTTP_X_FORWARDED_FOR') ?? self::ABSENT),
            __('Visitor address: %1', $this->visitorAddress()),
        ];

        return implode('<br/>', array_map(fn (Phrase $line): string => $this->escape($line->render()), $lines));
    }

    /**
     * The visitor address of the request, or the sentence that says it is not known
     *
     * The resolver is built when it is first asked, and a crawler or list declaration that a provider module got
     * wrong stops it from being built: that fault is shown in the row instead of keeping the configuration section
     * from opening.
     *
     * @return string|Phrase The address; the sentence stating that it cannot be determined otherwise
     */
    private function visitorAddress(): string|Phrase
    {
        try {
            return $this->clientAddressResolver->resolve() ?? __(
                'cannot be determined from this request\'s forwarding chain — run bin/magento '
                . 'bot-verification:check with the same addresses to reproduce it'
            );
        } catch (\InvalidArgumentException $exception) {
            return __('cannot be determined: %1', $exception->getMessage());
        }
    }

    /**
     * A server value of the request as text
     *
     * @param string $name Name of the server value
     * @return string|null Null when the request is not an HTTP request or does not carry the value as text
     */
    private function serverValue(string $name): ?string
    {
        $request = $this->getRequest();
        if (!$request instanceof HttpRequest) {
            return null;
        }
        $value = $request->getServer($name);

        return is_string($value) ? $value : null;
    }

    /**
     * Text escaped for HTML
     *
     * @param string $text Text to escape
     * @return string
     */
    private function escape(string $text): string
    {
        $escaped = $this->escaper->escapeHtml($text);

        // Text in gives text out; the other branch exists for arrays, which are never passed here, and printing
        // nothing is the one reading of it that is never unsafe.
        return is_string($escaped) ? $escaped : '';
    }
}
