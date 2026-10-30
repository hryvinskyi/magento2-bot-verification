<?php
/**
 * Copyright (c) 2026. Volodymyr Hryvinskyi. All rights reserved.
 * Author: Volodymyr Hryvinskyi <volodymyr@hryvinskyi.com>
 * GitHub: https://github.com/hryvinskyi
 */

declare(strict_types=1);

namespace Hryvinskyi\BotVerification\Test\Unit\Plugin;

use Hryvinskyi\BotVerification\Api\Data\VerdictStatus;
use Hryvinskyi\BotVerification\Api\RequestVerifierInterface;
use Hryvinskyi\BotVerification\Model\Verdict;
use Hryvinskyi\BotVerification\Plugin\RefuseImpostorPlugin;
use Hryvinskyi\BotVerification\Test\Double\FixedConfig;
use Magento\Framework\App\FrontControllerInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\App\Response\HttpInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Only an impostor judged while refusal is on is refused, with the injected response turned into an uncacheable 403;
 * every other request - refusal off, any other verdict, a verification that fails, a response that is not an HTTP
 * one - is dispatched as usual.
 */
class RefuseImpostorPluginTest extends TestCase
{
    /**
     * Number of times the rest of the chain was called
     */
    private int $proceedCalls = 0;

    /**
     * With refusal off the verifier is never asked and the request is dispatched
     *
     * @return void
     */
    public function testRefusalOffNeverAsksTheVerifier(): void
    {
        $verifier = $this->createMock(RequestVerifierInterface::class);
        $verifier->expects(self::never())->method('verify');
        $dispatched = $this->createMock(ResponseInterface::class);

        $result = $this->plugin(false, $verifier, $this->createMock(HttpInterface::class))
            ->aroundDispatch(...$this->dispatch($dispatched));

        self::assertSame($dispatched, $result);
        self::assertSame(1, $this->proceedCalls);
    }

    /**
     * A request that is not an impostor is dispatched, and the response is left alone
     *
     * @dataProvider verdictsThatAreNotImpostors
     * @param VerdictStatus $status The verdict on the request
     * @param string|null $crawlerCode The crawler it names
     * @return void
     */
    public function testANonImpostorIsDispatched(VerdictStatus $status, ?string $crawlerCode): void
    {
        $response = $this->createMock(HttpInterface::class);
        $response->expects(self::never())->method('setHttpResponseCode');
        $response->expects(self::never())->method('setBody');
        $dispatched = $this->createMock(ResponseInterface::class);

        $result = $this->plugin(true, $this->verifier(new Verdict($status, $crawlerCode)), $response)
            ->aroundDispatch(...$this->dispatch($dispatched));

        self::assertSame($dispatched, $result);
        self::assertSame(1, $this->proceedCalls);
    }

    /**
     * Verdicts that let a request through
     *
     * @return array<string, array{VerdictStatus, string|null}>
     */
    public static function verdictsThatAreNotImpostors(): array
    {
        return [
            'claims no crawler' => [VerdictStatus::None, null],
            'genuine' => [VerdictStatus::Genuine, 'example'],
            'unverifiable' => [VerdictStatus::Unverifiable, 'example'],
        ];
    }

    /**
     * An impostor is never dispatched: the injected response becomes a private, uncacheable plain-text 403 and is
     * returned
     *
     * @return void
     */
    public function testAnImpostorGetsTheInjectedResponseAsA403(): void
    {
        $headers = [];
        $response = $this->createMock(HttpInterface::class);
        $response->expects(self::once())->method('setHttpResponseCode')->with(403)->willReturnSelf();
        $response->expects(self::exactly(2))
            ->method('setHeader')
            ->willReturnCallback(
                static function (string $name, string $value, bool $replace = false) use (&$headers, $response) {
                    $headers[$name] = ['value' => $value, 'replace' => $replace];

                    return $response;
                }
            );
        $response->expects(self::once())->method('setBody')->with('Forbidden')->willReturnSelf();

        $result = $this->plugin(true, $this->verifier(new Verdict(VerdictStatus::Impostor, 'example')), $response)
            ->aroundDispatch(...$this->dispatch($this->createMock(ResponseInterface::class)));

        self::assertSame($response, $result);
        self::assertSame(0, $this->proceedCalls);
        self::assertEquals(
            [
                'Cache-Control' => [
                    'value' => 'private, no-store, no-cache, must-revalidate, max-age=0',
                    'replace' => true,
                ],
                'Content-Type' => ['value' => 'text/plain; charset=UTF-8', 'replace' => true],
            ],
            $headers
        );
    }

    /**
     * A verification that throws lets the request through
     *
     * @dataProvider verificationFailures
     * @param \Throwable $failure What the verification throws
     * @return void
     */
    public function testAFailedVerificationLetsTheRequestThrough(\Throwable $failure): void
    {
        $verifier = $this->createMock(RequestVerifierInterface::class);
        $verifier->method('verify')->willThrowException($failure);
        $response = $this->createMock(HttpInterface::class);
        $response->expects(self::never())->method('setHttpResponseCode');
        $dispatched = $this->createMock(ResponseInterface::class);

        $result = $this->plugin(true, $verifier, $response)->aroundDispatch(...$this->dispatch($dispatched));

        self::assertSame($dispatched, $result);
        self::assertSame(1, $this->proceedCalls);
    }

    /**
     * An exception, as a malformed declaration throws, and an error, as a bug does
     *
     * @return array<string, array{\Throwable}>
     */
    public static function verificationFailures(): array
    {
        return [
            'exception' => [new \InvalidArgumentException('A crawler declares no token.')],
            'error' => [new \Error('Call to a member function on null')],
        ];
    }

    /**
     * An injected response that is not an HTTP response cannot carry a 403, so even an impostor is dispatched
     *
     * @return void
     */
    public function testAResponseThatIsNotAnHttpResponseLetsTheRequestThrough(): void
    {
        $dispatched = $this->createMock(ResponseInterface::class);

        $result = $this->plugin(
            true,
            $this->verifier(new Verdict(VerdictStatus::Impostor, 'example')),
            $this->createMock(ResponseInterface::class)
        )->aroundDispatch(...$this->dispatch($dispatched));

        self::assertSame($dispatched, $result);
        self::assertSame(1, $this->proceedCalls);
    }

    /**
     * A controller result the rest of the chain returns is passed on unchanged
     *
     * @return void
     */
    public function testAControllerResultIsPassedOn(): void
    {
        $dispatched = $this->createMock(ResultInterface::class);

        $result = $this->plugin(
            true,
            $this->verifier(new Verdict(VerdictStatus::Genuine, 'example')),
            $this->createMock(HttpInterface::class)
        )->aroundDispatch(...$this->dispatch($dispatched));

        self::assertSame($dispatched, $result);
    }

    /**
     * A chain that returns neither a response nor a result is a programming error, not something to pass on
     *
     * @return void
     */
    public function testAChainReturningSomethingElseFails(): void
    {
        $plugin = $this->plugin(
            false,
            $this->createMock(RequestVerifierInterface::class),
            $this->createMock(HttpInterface::class)
        );

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage(
            'The front controller returned string instead of a response or a controller result.'
        );
        $plugin->aroundDispatch(...$this->dispatch('not a response'));
    }

    /**
     * The plugin over the given switch, verifier and response
     *
     * @param bool $isRefusalEnabled Whether refusal is on
     * @param RequestVerifierInterface $verifier The verdict on the request
     * @param ResponseInterface $response The injected response
     * @return RefuseImpostorPlugin
     */
    private function plugin(
        bool $isRefusalEnabled,
        RequestVerifierInterface $verifier,
        ResponseInterface $response
    ): RefuseImpostorPlugin {
        return new RefuseImpostorPlugin(new FixedConfig([], $isRefusalEnabled), $verifier, $response);
    }

    /**
     * A verifier that judges every request with the given verdict
     *
     * @param Verdict $verdict The verdict
     * @return RequestVerifierInterface&MockObject
     */
    private function verifier(Verdict $verdict): RequestVerifierInterface&MockObject
    {
        $verifier = $this->createMock(RequestVerifierInterface::class);
        $verifier->method('verify')->willReturn($verdict);

        return $verifier;
    }

    /**
     * The arguments of one dispatch: a front controller, a chain that counts its calls and returns the given value,
     * and the request it checks it is called with
     *
     * @param ResponseInterface|ResultInterface|string $dispatched What the rest of the chain returns
     * @return array{
     *     FrontControllerInterface,
     *     \Closure(RequestInterface): (ResponseInterface|ResultInterface|string),
     *     RequestInterface
     * }
     */
    private function dispatch(ResponseInterface|ResultInterface|string $dispatched): array
    {
        $request = $this->createMock(RequestInterface::class);
        $proceed = function (
            RequestInterface $passed
        ) use (
            $request,
            $dispatched
        ): ResponseInterface|ResultInterface|string {
            ++$this->proceedCalls;
            self::assertSame($request, $passed);

            return $dispatched;
        };

        return [$this->createMock(FrontControllerInterface::class), $proceed, $request];
    }
}
