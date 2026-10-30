<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Integration\Block\Adminhtml\System\Config;

use Hryvinskyi\BotVerification\Block\Adminhtml\System\Config\AddressCheck;
use Hryvinskyi\BotVerification\Model\Address\ForwardingChainResolver;
use Hryvinskyi\BotVerification\Model\Address\ProxyChainClientAddressResolver;
use Hryvinskyi\BotVerification\Model\Address\TrustedProxyRanges;
use Hryvinskyi\BotVerification\Model\Crawler\CrawlerRegistry;
use Laminas\Stdlib\Parameters;
use Magento\Backend\Block\Template\Context;
use Magento\Framework\Data\Form;
use Magento\Framework\Data\Form\Element\Text;
use Magento\Framework\Escaper;
use Magento\TestFramework\Helper\Bootstrap;
use Magento\TestFramework\Request;
use PHPUnit\Framework\TestCase;

/**
 * The address-check row, rendered the way the configuration form renders a field: it shows the request's connecting
 * address, its X-Forwarded-For header escaped, and the visitor address resolved past the shipped trusted proxies.
 *
 * Proxy membership is decided over a neutral registry that declares no proxy list, so the lists other enabled modules
 * declare do not leak in. For the same reason the rows that are rendered are built directly over the container's
 * context, escaper and resolver: the container wraps a configuration row in the plugins every installed module
 * declares on the base row class, and how those render is not this module's to prove. That the container builds the
 * row is proven on its own.
 *
 * @magentoAppArea adminhtml
 */
class AddressCheckTest extends TestCase
{
    /**
     * The container builds the row with its configured collaborators
     *
     * @return void
     */
    public function testTheContainerBuildsTheRow(): void
    {
        self::assertInstanceOf(AddressCheck::class, Bootstrap::getObjectManager()->create(AddressCheck::class));
    }

    /**
     * A script written into the header left of the client is never read by the walk, is printed escaped, and the
     * visitor behind the loopback proxy is found
     *
     * @return void
     */
    public function testShowsTheRequestEscapedAndTheVisitor(): void
    {
        $html = self::block(
            '127.0.0.1',
            '<script>alert(1)</script>, 198.51.100.7'
        )->render(self::element());

        self::assertStringContainsString('Connection from: 127.0.0.1', $html);
        self::assertStringContainsString(
            'X-Forwarded-For: &lt;script&gt;alert(1)&lt;/script&gt;, 198.51.100.7',
            $html
        );
        self::assertStringContainsString('Visitor address: 198.51.100.7', $html);
        self::assertStringNotContainsString('<script>alert', $html);
    }

    /**
     * A request that reached the shop only through trusted proxies has no visitor address, and the row says why;
     * a header the request does not carry is shown as a dash
     *
     * @return void
     */
    public function testExplainsAVisitorThatCannotBeDetermined(): void
    {
        $html = self::block('127.0.0.1', null)->render(self::element());

        self::assertStringContainsString('X-Forwarded-For: —', $html);
        self::assertStringContainsString(
            'Visitor address: cannot be determined — the request reached the shop only through trusted proxies, '
            . 'or a proxy list has not been fetched yet',
            $html
        );
    }

    /**
     * The row over a fresh request carrying the given values, and a resolver over the same request, built over the
     * container's context and escaper
     *
     * @param string $remoteAddress Connecting address
     * @param string|null $forwardedFor X-Forwarded-For header; null when the request carries none
     * @return AddressCheck
     */
    private static function block(string $remoteAddress, ?string $forwardedFor): AddressCheck
    {
        $objectManager = Bootstrap::getObjectManager();
        $request = $objectManager->create(Request::class);
        self::assertInstanceOf(Request::class, $request);
        $server = ['REMOTE_ADDR' => $remoteAddress];
        if ($forwardedFor !== null) {
            $server['HTTP_X_FORWARDED_FOR'] = $forwardedFor;
        }
        $request->setServer(new Parameters($server));

        $registry = $objectManager->create(CrawlerRegistry::class, [
            'crawlers' => [],
            'rangeLists' => [],
            'rangeListFormats' => [],
            'proxyLists' => [],
        ]);
        $trustedProxies = $objectManager->create(TrustedProxyRanges::class, ['registry' => $registry]);
        $chainResolver = $objectManager->create(ForwardingChainResolver::class, ['trustedProxies' => $trustedProxies]);
        $resolver = $objectManager->create(ProxyChainClientAddressResolver::class, [
            'request' => $request,
            'chainResolver' => $chainResolver,
        ]);

        $context = $objectManager->create(Context::class, ['request' => $request]);
        self::assertInstanceOf(Context::class, $context);
        self::assertInstanceOf(ProxyChainClientAddressResolver::class, $resolver);
        $escaper = $objectManager->get(Escaper::class);
        self::assertInstanceOf(Escaper::class, $escaper);

        return new AddressCheck($context, $resolver, $escaper);
    }

    /**
     * A configuration field on a form, as the configuration form hands it to its renderer
     *
     * @return Text
     */
    private static function element(): Text
    {
        $objectManager = Bootstrap::getObjectManager();
        $form = $objectManager->create(Form::class);
        self::assertInstanceOf(Form::class, $form);
        $element = $objectManager->create(Text::class);
        self::assertInstanceOf(Text::class, $element);
        $element->setForm($form);
        $element->setId('hryvinskyi_bot_verification_client_address_address_check');
        $element->setData('label', 'This request as the shop sees it');

        return $element;
    }
}
