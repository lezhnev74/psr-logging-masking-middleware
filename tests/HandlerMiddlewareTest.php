<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Genericity proof for the handler-stack middleware. HandlerMiddleware::for()
 * returns a push-ready closure typed only against PSR-7 and a structural
 * "thenable" contract - no GuzzleHttp\* anywhere - so it is exercised here with a
 * hand-rolled thenable, not Guzzle. The Guzzle-native proof lives in
 * GuzzleClientTest; this pins the contract the helper actually depends on.
 */
final class HandlerMiddlewareTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testTapsResolvedResponseThroughLoggerAndReturnsIt(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $response = $factory->createResponse(200)->withHeader('Set-Cookie', 'session=deadbeef');

        $logger = new TestLogger();
        $middleware = HandlerMiddleware::for($this->tap($logger, $factory));

        // Inner handler resolves the response through a thenable.
        $handler = static fn (RequestInterface $request, array $options): FulfilledThenable => new FulfilledThenable($response);
        $composed = $middleware($handler);

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $settled = $composed($request, []);
        self::assertInstanceOf(FulfilledThenable::class, $settled);

        self::assertSame($response, $settled->resolve());

        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringContainsString('Set-Cookie: ***', $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringNotContainsString('deadbeef', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testLogsFailureAndRethrowsWhenHandlerRejects(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $error = new \RuntimeException('connect timeout');

        $logger = new TestLogger();
        $middleware = HandlerMiddleware::for($this->tap($logger, $factory));

        $handler = static fn (RequestInterface $request, array $options): RejectedThenable => new RejectedThenable($error);
        $composed = $middleware($handler);

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $settled = $composed($request, []);
        self::assertInstanceOf(RejectedThenable::class, $settled);

        try {
            $settled->resolve();
            self::fail('Expected the rejection reason to propagate.');
        } catch (\Throwable $caught) {
            self::assertSame($error, $caught);
        }

        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringContainsString('connect timeout', $message);
    }

    private function tap(
        TestLogger $logger,
        StreamFactoryInterface $streamFactory,
    ): MessageLogger {
        return new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization']),
            MaskingConfig::create(headerNames: ['Set-Cookie']),
            new MessageMasker($streamFactory),
        );
    }
}

/**
 * Minimal thenable that resolves to a value, matching the fulfilment half of the
 * promise contract the middleware taps - stands in for Guzzle's PromiseInterface.
 */
final class FulfilledThenable
{
    public function __construct(private readonly mixed $value)
    {
    }

    /**
     * @param  callable(mixed): mixed  $onFulfilled
     * @param  callable(\Throwable): mixed  $onRejected
     */
    public function then(callable $onFulfilled, callable $onRejected): self
    {
        return new self($onFulfilled($this->value));
    }

    public function resolve(): mixed
    {
        return $this->value;
    }
}

/**
 * Minimal thenable that rejects with a throwable and re-raises whatever the
 * onRejected callback throws - the rejection half of the contract.
 */
final class RejectedThenable
{
    public function __construct(private readonly \Throwable $reason)
    {
    }

    /**
     * @param  callable(mixed): mixed  $onFulfilled
     * @param  callable(\Throwable): mixed  $onRejected
     */
    public function then(callable $onFulfilled, callable $onRejected): self
    {
        try {
            $onRejected($this->reason);
        } catch (\Throwable $rethrown) {
            return new self($rethrown);
        }

        return $this;
    }

    /**
     * @throws \Throwable
     */
    public function resolve(): mixed
    {
        throw $this->reason;
    }
}
