<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\HttpFactory;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Response as LaravelResponse;
use Illuminate\Support\Facades\Http;
use Lezhnev74\PsrLoggingMaskingMiddleware\HandlerMiddleware;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\Redaction;
use PHPUnit\Framework\TestCase;

/**
 * Integration proof for the most common real-world consumer: Laravel's HTTP client,
 * driven through the `Http` facade. Laravel wraps Guzzle and exposes its handler
 * stack via request options, so the generic HandlerMiddleware pushes onto that stack
 * exactly as it does for a bare Guzzle client - with no GuzzleHttp\* or Illuminate\*
 * dependency in src/. The facade is given a plain Factory to resolve against (no
 * container boot needed), and MockHandler keeps the exchange offline while it still
 * flows through Laravel's real request pipeline, response objects and exception types.
 */
final class LaravelHttpFacadeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Http::swap(new Factory());
    }

    protected function tearDown(): void
    {
        Http::clearResolvedInstances();

        parent::tearDown();
    }

    public function testLogsMaskedExchangeAndReturnsLaravelResponse(): void
    {
        $mock = new MockHandler([
            new Response(200, ['Set-Cookie' => 'session=deadbeef'], 'hello'),
        ]);
        $logger = new TestLogger();

        $response = $this->sendThroughLaravel($mock, $logger);

        // Laravel's response is returned untouched.
        self::assertInstanceOf(LaravelResponse::class, $response);
        self::assertSame(200, $response->status());
        self::assertSame('hello', $response->body());

        // One combined, masked record was logged.
        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringContainsString('Set-Cookie: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringNotContainsString('deadbeef', $message);
    }

    public function testLogsFailureAndRethrowsWhenTransportFails(): void
    {
        $error = new ConnectException('connect timeout', new Request('GET', 'https://example.com/'));

        $mock = new MockHandler([$error]);
        $logger = new TestLogger();

        try {
            $this->sendThroughLaravel($mock, $logger);
            self::fail('Expected the transport failure to propagate.');
        } catch (ConnectionException $caught) {
            // Laravel wraps Guzzle's transport exception in its own ConnectionException.
            self::assertStringContainsString('connect timeout', $caught->getMessage());
        }

        // The request was still logged, with the failure noted from the underlying
        // Guzzle exception the middleware sees before Laravel wraps it.
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
        // Laravel's client does not throw on 5xx by default, so the 500 flows through
        // the middleware and is logged as a normal exchange.
        $mock = new MockHandler([new Response(500, [], 'server error')]);
        $logger = new TestLogger();

        $response = $this->sendThroughLaravel($mock, $logger);

        self::assertSame(500, $response->status());

        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('500', $message);
        self::assertStringNotContainsString('<failed:', $message);
    }

    /**
     * Sends a request through the Laravel `Http` facade with the generic logging
     * middleware pushed onto the Guzzle handler stack the facade drives - the
     * idiomatic way to tap a Laravel HTTP client, with no Illuminate\* dependency
     * in src/.
     */
    private function sendThroughLaravel(MockHandler $mock, TestLogger $logger): LaravelResponse
    {
        $tap = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization']),
            MaskingConfig::create(headerNames: ['Set-Cookie']),
            new MessageMasker(new HttpFactory()),
        );

        $stack = HandlerStack::create($mock);
        $stack->push(HandlerMiddleware::for($tap));

        return Http::withOptions(['handler' => $stack])
            ->withHeaders(['Authorization' => 'Bearer super-secret'])
            ->get('https://example.com/');
    }
}
