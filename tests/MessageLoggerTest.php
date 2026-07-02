<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageSerializer;
use Lezhnev74\PsrLoggingMaskingMiddleware\Redaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LogLevel;

/**
 * Cross-impl entry-point tests: the same assertions run against every PSR-7
 * implementation the harness provides, proving the middleware logs through the
 * injected PSR-3 logger and masks/serializes via the package engines only -
 * never a concrete PSR-7 implementation.
 */
final class MessageLoggerTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testLogsCombinedRequestAndResponseAtDebugWithMaskedContent(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization'], queryNames: ['token'], bodyKeys: ['password']),
            MaskingConfig::create(headerNames: ['Set-Cookie']),
            new MessageMasker($factory),
        );

        $request = $factory->createRequest('POST', 'https://api.example.com/login?token=abc&page=2')
            ->withHeader('Authorization', 'Bearer super-secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"hunter2","user":"ann"}'));
        $response = $factory->createResponse(200)
            ->withHeader('Set-Cookie', 'session=deadbeef')
            ->withBody($factory->createStream('ok'));

        $middleware->log($request, $response);

        self::assertCount(1, $logger->records);
        [$record] = $logger->records;
        self::assertSame(LogLevel::DEBUG, $record['level']);

        $message = $record['message'];
        self::assertIsString($message);
        self::assertStringContainsString('HTTP request:', $message);
        self::assertStringContainsString('HTTP response:', $message);
        // Request secrets redacted: header, query arg, JSON body key.
        self::assertStringContainsString('Authorization: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringContainsString('token=' . rawurlencode(Redaction::PLACEHOLDER), $message);
        self::assertStringContainsString('"password":"' . Redaction::PLACEHOLDER . '"', $message);
        self::assertStringContainsString('"user":"ann"', $message);
        // Response secret redacted.
        self::assertStringContainsString('Set-Cookie: ' . Redaction::PLACEHOLDER, $message);
        // Nothing leaked verbatim.
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringNotContainsString('hunter2', $message);
        self::assertStringNotContainsString('deadbeef', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testLogsUnmaskedWhenConfigIsNull(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new MessageLogger($logger, null, null, new MessageMasker($factory));

        $request = $factory->createRequest('GET', 'https://example.com/?token=abc')
            ->withHeader('Authorization', 'Bearer super-secret');
        $response = $factory->createResponse(200)->withHeader('Set-Cookie', 'session=deadbeef');

        $middleware->log($request, $response);

        [$record] = $logger->records;
        $message = $record['message'];
        self::assertIsString($message);
        self::assertStringContainsString('super-secret', $message);
        self::assertStringContainsString('deadbeef', $message);
        self::assertStringNotContainsString(Redaction::PLACEHOLDER, $message);
    }

    #[DataProvider('psr7Factories')]
    public function testLogFailureLogsRequestAndNotesTheError(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization']),
            null,
            new MessageMasker($factory),
        );

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $middleware->logFailure($request, new \RuntimeException('connect timeout'));

        self::assertCount(1, $logger->records);
        [$record] = $logger->records;
        self::assertSame(LogLevel::DEBUG, $record['level']);
        $message = $record['message'];
        self::assertIsString($message);
        self::assertStringContainsString('HTTP request:', $message);
        self::assertStringContainsString('Authorization: ' . Redaction::PLACEHOLDER, $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringContainsString('RuntimeException', $message);
        self::assertStringContainsString('connect timeout', $message);
        self::assertStringContainsString('failed', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testSerializedShapeMatchesMessageSerializer(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $masker = new MessageMasker($factory);
        $serializer = new MessageSerializer();
        $config = MaskingConfig::create(headerNames: ['Authorization']);

        $logger = new TestLogger();
        $middleware = new MessageLogger($logger, $config, null, $masker, $serializer);

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');
        $response = $factory->createResponse(204);

        $middleware->log($request, $response);

        $expectedRequest = $serializer->serialize($masker->mask($request, $config));
        [$record] = $logger->records;
        $message = $record['message'];
        self::assertIsString($message);
        self::assertStringContainsString($expectedRequest, $message);
    }

    #[DataProvider('psr7Factories')]
    public function testDoesNotConsumeOrMutateTheOriginalMessage(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new MessageLogger(
            $logger,
            MaskingConfig::create(headerNames: ['Authorization']),
            MaskingConfig::create(headerNames: ['Set-Cookie']),
            new MessageMasker($factory),
        );

        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret')
            ->withBody($factory->createStream('request-body'));
        $response = $factory->createResponse(200)
            ->withHeader('Set-Cookie', 'session=deadbeef')
            ->withBody($factory->createStream('response-body'));

        $middleware->log($request, $response);

        // Bodies still fully readable by the caller.
        self::assertSame('request-body', (string) $request->getBody());
        self::assertSame('response-body', (string) $response->getBody());
        // Originals untouched - masking returned clones.
        self::assertSame('Bearer super-secret', $request->getHeaderLine('Authorization'));
        self::assertSame('session=deadbeef', $response->getHeaderLine('Set-Cookie'));
    }
}
