<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\LoggingClient;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientExceptionInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Cross-impl tests for the PSR-18 decorator: it is a ClientInterface that taps
 * the exchange through MessageLogger and returns the inner response untouched,
 * logging any failed send (any Throwable, not just ClientExceptionInterface) via
 * logFailure(). Guzzle is never imported here - the decorator is driven through
 * hand-rolled PSR-18 stub clients so the proof stays implementation-agnostic.
 */
final class LoggingClientTest extends PsrImplTestCase
{
    public function testItIsAPsr18Client(): void
    {
        $inner = $this->stubClient(static fn (): ResponseInterface => throw new \LogicException('unused'));
        $tap = new MessageLogger(new TestLogger(), MaskingConfig::create());

        self::assertInstanceOf(ClientInterface::class, new LoggingClient($inner, $tap));
    }

    #[DataProvider('psr7Factories')]
    public function testLogsMaskedExchangeAndReturnsInnerResponseUnchanged(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $response = $factory->createResponse(200)->withHeader('Set-Cookie', 'session=deadbeef');
        $inner = $this->stubClient(static fn (): ResponseInterface => $response);

        $logger = new TestLogger();
        $tap = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization', 'Set-Cookie']),
            new MessageMasker($factory),
        );
        $client = new LoggingClient($inner, $tap);

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $returned = $client->sendRequest($request);

        // Inner response passes through untouched.
        self::assertSame($response, $returned);

        // One combined, masked record was logged.
        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringContainsString('Set-Cookie: ***', $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringNotContainsString('deadbeef', $message);
    }

    /**
     * The inner client may throw more than the declared ClientExceptionInterface
     * (a bug, a TypeError, a transport exception that skips the interface). The
     * decorator catches any Throwable so the sent request is always logged before
     * the throwable propagates unchanged.
     */
    #[DataProvider('throwablesAcrossImpls')]
    public function testLogsFailureAndRethrowsWhenInnerClientThrowsAnyThrowable(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
        \Throwable $error,
    ): void {
        $inner = $this->stubClient(static fn (): ResponseInterface => throw $error);

        $logger = new TestLogger();
        $tap = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization']),
            new MessageMasker($factory),
        );
        $client = new LoggingClient($inner, $tap);

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        try {
            $client->sendRequest($request);
            self::fail('Expected the inner throwable to propagate.');
        } catch (\Throwable $caught) {
            self::assertSame($error, $caught);
        }

        // The request was still logged, with the failure noted.
        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringContainsString($error->getMessage(), $message);
    }

    /**
     * A PSR-18 transport error and a plain throwable that does NOT implement
     * ClientExceptionInterface - both must be caught, logged and re-thrown.
     *
     * @return array<string, array{\Throwable}>
     */
    public static function throwables(): array
    {
        return [
            'psr-18 client exception' => [
                new class ('connect timeout') extends \RuntimeException implements ClientExceptionInterface {},
            ],
            'plain throwable' => [new \RuntimeException('unexpected boom')],
        ];
    }

    /**
     * @return iterable<string, array{RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface, \Throwable}>
     */
    public static function throwablesAcrossImpls(): iterable
    {
        foreach (self::psr7Factories() as $implName => [$factory]) {
            foreach (self::throwables() as $caseName => [$error]) {
                yield "{$implName} / {$caseName}" => [$factory, $error];
            }
        }
    }

    /**
     * Minimal PSR-18 client whose sendRequest() defers to the given callable.
     *
     * @param  callable(RequestInterface): ResponseInterface  $handler
     */
    private function stubClient(callable $handler): ClientInterface
    {
        return new class ($handler) implements ClientInterface {
            /** @param callable(RequestInterface): ResponseInterface $handler */
            public function __construct(private $handler)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return ($this->handler)($request);
            }
        };
    }
}
