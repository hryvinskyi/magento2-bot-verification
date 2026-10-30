<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Plugin;

use Hryvinskyi\BotVerification\Api\ConfigInterface;
use Hryvinskyi\BotVerification\Api\RequestVerifierInterface;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;

/**
 * Answers a crawler impostor with a 403 before the front controller routes its request
 *
 * It wraps dispatch rather than following it because a refused request must never reach routing: the front
 * controller is not called at all for an impostor.
 *
 * The refusal is the area's shared response object, given a 403, never a controller result: the application sends an
 * HTTP response returned from dispatch in every area, so the same object serves storefront, admin, REST, SOAP and
 * GraphQL requests, and a plugin outside this one that accepts only an HTTP response can still take it.
 *
 * The refusal says `private` and `no-store`. A cache in front of the shop that rewrites every response not marked
 * private into a short public one would otherwise store the 403 - of a robots.txt the front controller serves, say -
 * and answer the real crawlers with it.
 *
 * It is the outermost front-controller plugin. A plugin declared without an order is given order 0, and this one
 * declares a negative order, so it runs before the built-in page cache, the form-key plugin, the no-store plugin of
 * the REST and SOAP areas and every other front-controller plugin. With refusal on, an impostor is refused even for a
 * page the built-in cache holds, and every request that reaches PHP pays for the claim check; a request an HTTP cache
 * in front of the shop answers never reaches PHP. On a refusal the plugins inside it do not run - the no-store
 * plugin among them - so the refusal sets its own cache headers.
 *
 * Verification fails open: any error while judging the request - a malformed crawler declaration, an unreadable
 * stored list, a bug - lets the request through instead of answering it with an error. The judgement is built on
 * every request while refusal is on, so failing loudly here would turn one bad declaration into an error on every
 * page. Nothing is logged, which would be one line per request: the refresh job and the command-line tools build the
 * same objects and fail loudly there. A refusal is not logged either; the web server's access log shows each 403
 * with its User-Agent.
 *
 * With refusal off nothing behind the verifier is built. With it on, a request that claims no crawler pays for
 * building the verifier's small object graph and one regular-expression scan of its User-Agent; it reads no stored
 * list and resolves no client address.
 */
class RefuseImpostorPlugin
{
    private const STATUS_FORBIDDEN = 403;
    private const CACHE_CONTROL = 'private, no-store, no-cache, must-revalidate, max-age=0';
    private const CONTENT_TYPE = 'text/plain; charset=UTF-8';
    private const BODY = 'Forbidden';

    /**
     * @param ConfigInterface $config Whether refusal is on
     * @param RequestVerifierInterface $requestVerifier The verdict on the current request; built only when asked
     * @param ResponseInterface $response The area's shared response, which the refusal fills and returns
     */
    public function __construct(
        private readonly ConfigInterface $config,
        private readonly RequestVerifierInterface $requestVerifier,
        private readonly ResponseInterface $response
    ) {
    }

    /**
     * Refuse an impostor with a 403 that no cache may store; dispatch every other request as usual
     *
     * @param FrontControllerInterface $subject The front controller; not used
     * @param callable $proceed The rest of the dispatch chain
     * @param RequestInterface $request The request to dispatch
     * @return ResponseInterface|ResultInterface The refusal, or what the rest of the chain returns
     * @throws \LogicException When the rest of the chain returns neither a response nor a result
     */
    public function aroundDispatch(
        FrontControllerInterface $subject,
        callable $proceed,
        RequestInterface $request
    ): ResponseInterface|ResultInterface {
        $response = $this->response;
        if (!$this->config->isImpostorRefusalEnabled()
            || !$response instanceof HttpInterface
            || !$this->isImpostor()
        ) {
            return $this->proceed($proceed, $request);
        }

        $response->setHttpResponseCode(self::STATUS_FORBIDDEN);
        $response->setHeader('Cache-Control', self::CACHE_CONTROL, true);
        $response->setHeader('Content-Type', self::CONTENT_TYPE, true);
        $response->setBody(self::BODY);

        return $response;
    }

    /**
     * Whether the current request is a crawler impostor; false when it cannot be judged
     *
     * @return bool
     */
    private function isImpostor(): bool
    {
        try {
            return $this->requestVerifier->verify()->isImpostor();
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Dispatch the request through the rest of the chain
     *
     * @param callable $proceed The rest of the dispatch chain
     * @param RequestInterface $request The request to dispatch
     * @return ResponseInterface|ResultInterface
     * @throws \LogicException When the chain returns neither a response nor a result
     */
    private function proceed(callable $proceed, RequestInterface $request): ResponseInterface|ResultInterface
    {
        $result = $proceed($request);
        if ($result instanceof ResponseInterface || $result instanceof ResultInterface) {
            return $result;
        }

        throw new \LogicException(sprintf(
            'The front controller returned %s instead of a response or a controller result.',
            get_debug_type($result)
        ));
    }
}
