<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\Redaction;
use PHPUnit\Framework\TestCase;

/**
 * Integration proof: the package's generic HandlerMiddleware pushes cleanly onto
 * a real Guzzle HandlerStack - the idiomatic way to tap a Guzzle client, with no
 * GuzzleHttp\* dependency in src/. HandlerMiddleware::for($messageLogger) yields
 * the push-ready closure; it taps the resolved promise, calling
 * MessageLogger::log() on fulfilment and logFailure() on rejection. Guzzle is
 * driven through MockHandler so there is no network, yet the exchange still flows
 * through Guzzle's real handler stack, response objects and exception types,
 * proving the masked-logging behaviour holds when wired the native Guzzle way.
 */
final class GuzzleClientTest extends TestCase
{
    public function testLogsMaskedExchangeAndReturnsGuzzleResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'session=deadbeef'], 'hello'),
        ]);
        $logger = new TestLogger();
        $client = $this->guzzleWithLogging($mock, $logger);

        $request = (new Request('GET', 'https://example.com/'))
            ->withHeader('Authorization', 'Bearer super-secret');

        $response = $client->send($request);

        // Guzzle's response is returned untouched.
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('hello', (string) $response->getBody());

        // One combined, masked record was logged.
        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringContainsString('Set-Cookie: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringNotContainsString('deadbeef', $message);
    }

    public function testLogsFailureAndRethrowsWhenGuzzleThrows(): void
    {
        $request = (new Request('GET', 'https://example.com/'))
            ->withHeader('Authorization', 'Bearer super-secret');
        $error = new ConnectException('connect timeout', $request);

        $mock = new MockHandler([$error]);
        $logger = new TestLogger();
        $client = $this->guzzleWithLogging($mock, $logger);

        try {
            $client->send($request);
            self::fail('Expected the Guzzle transport exception to propagate.');
        } catch (\Throwable $caught) {
            self::assertSame($error, $caught);
        }

        // The request was still logged, with the failure noted.
        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringContainsString('connect timeout', $message);
        self::assertStringContainsString(ConnectException::class, $message);
    }

    public function testLogsAndReturnsErrorResponseWithoutThrowing(): void
    {
        // With http_errors disabled Guzzle resolves 5xx to a response, so the
        // 500 flows through the middleware and is logged as a normal exchange.
        $mock = new MockHandler([new Response(500, [], 'server error')]);
        $logger = new TestLogger();
        $client = $this->guzzleWithLogging($mock, $logger);

        $response = $client->send(new Request('GET', 'https://example.com/'), ['http_errors' => false]);

        self::assertSame(500, $response->getStatusCode());

        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('500', $message);
        self::assertStringNotContainsString('<failed:', $message);
    }

    /**
     * Builds a real Guzzle client whose handler stack carries a native logging
     * middleware backed by MessageLogger - no PSR-18 decorator involved.
     */
    private function guzzleWithLogging(MockHandler $mock, TestLogger $logger): Client
    {
        $tap = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization']),
            MaskingConfig::create(headerNames: ['Set-Cookie']),
            new MessageMasker(new HttpFactory()),
        );

        $stack = HandlerStack::create($mock);
        $stack->push(HandlerMiddleware::for($tap));

        return new Client(['handler' => $stack]);
    }
}
