<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
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
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringContainsString('token=' . rawurlencode('***'), $message);
        self::assertStringContainsString('"password":"***"', $message);
        self::assertStringContainsString('"user":"ann"', $message);
        // Response secret redacted.
        self::assertStringContainsString('Set-Cookie: ***', $message);
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
        self::assertStringNotContainsString('***', $message);
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
        self::assertStringContainsString('Authorization: ***', $message);
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
    public function testConstructorLogLevelIsUsedForBothPaths(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new MessageLogger(
            $logger,
            null,
            null,
            new MessageMasker($factory),
            logLevel: LogLevel::INFO,
        );

        $request = $factory->createRequest('GET', 'https://example.com/');

        $middleware->log($request, $factory->createResponse(200));
        $middleware->logFailure($request, new \RuntimeException('boom'));

        self::assertCount(2, $logger->records);
        self::assertSame(LogLevel::INFO, $logger->records[0]['level']);
        self::assertSame(LogLevel::INFO, $logger->records[1]['level']);
    }

    #[DataProvider('psr7Factories')]
    public function testSubclassCanOverrideLogLevel(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new class ($logger, null, null, new MessageMasker($factory)) extends MessageLogger {
            protected function logLevel(): string
            {
                return LogLevel::WARNING;
            }
        };

        $request = $factory->createRequest('GET', 'https://example.com/');
        $response = $factory->createResponse(200);

        $middleware->log($request, $response);
        $middleware->logFailure($request, new \RuntimeException('boom'));

        self::assertCount(2, $logger->records);
        self::assertSame(LogLevel::WARNING, $logger->records[0]['level']);
        self::assertSame(LogLevel::WARNING, $logger->records[1]['level']);
    }

    #[DataProvider('psr7Factories')]
    public function testShouldLogSkipsBothPathsWithoutEmission(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        // Skip health-checks; log everything else.
        $middleware = new class ($logger, null, null, new MessageMasker($factory)) extends MessageLogger {
            protected function shouldLog(RequestInterface $request, ?ResponseInterface $response, ?\Throwable $error): bool
            {
                return $request->getUri()->getPath() !== '/health';
            }
        };

        $health = $factory->createRequest('GET', 'https://example.com/health');
        $other = $factory->createRequest('GET', 'https://example.com/api');

        $middleware->log($health, $factory->createResponse(200));
        $middleware->logFailure($health, new \RuntimeException('down'));
        $middleware->log($other, $factory->createResponse(200));

        self::assertCount(1, $logger->records);
        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('/api', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testResolvesMaskingConfigPerMessage(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        // Mask the Authorization header only for /secure; leave /open untouched.
        $middleware = new class ($logger, null, null, new MessageMasker($factory)) extends MessageLogger {
            protected function resolveRequestConfig(RequestInterface $request): ?MaskingConfig
            {
                return $request->getUri()->getPath() === '/secure'
                    ? MaskingConfig::create(headerNames: ['Authorization'])
                    : null;
            }
        };

        $secure = $factory->createRequest('GET', 'https://example.com/secure')
            ->withHeader('Authorization', 'Bearer secret-a');
        $open = $factory->createRequest('GET', 'https://example.com/open')
            ->withHeader('Authorization', 'Bearer secret-b');

        $middleware->log($secure, $factory->createResponse(200));
        $middleware->log($open, $factory->createResponse(200));

        [$secureRecord, $openRecord] = [$logger->records[0]['message'], $logger->records[1]['message']];
        self::assertIsString($secureRecord);
        self::assertIsString($openRecord);
        self::assertStringContainsString('Authorization: ***', $secureRecord);
        self::assertStringNotContainsString('secret-a', $secureRecord);
        self::assertStringContainsString('secret-b', $openRecord);
    }

    #[DataProvider('psr7Factories')]
    public function testResolveResponseConfigIsKeyedOnRequest(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new class ($logger, null, null, new MessageMasker($factory)) extends MessageLogger {
            protected function resolveResponseConfig(RequestInterface $request, ResponseInterface $response): ?MaskingConfig
            {
                return $request->getMethod() === 'POST'
                    ? MaskingConfig::create(headerNames: ['Set-Cookie'])
                    : null;
            }
        };

        $post = $factory->createRequest('POST', 'https://example.com/');
        $get = $factory->createRequest('GET', 'https://example.com/');
        $response = fn () => $factory->createResponse(200)->withHeader('Set-Cookie', 'session=deadbeef');

        $middleware->log($post, $response());
        $middleware->log($get, $response());

        [$postRecord, $getRecord] = [$logger->records[0]['message'], $logger->records[1]['message']];
        self::assertIsString($postRecord);
        self::assertIsString($getRecord);
        self::assertStringContainsString('Set-Cookie: ***', $postRecord);
        self::assertStringNotContainsString('deadbeef', $postRecord);
        self::assertStringContainsString('deadbeef', $getRecord);
    }

    #[DataProvider('psr7Factories')]
    public function testSubclassCanOverrideMessageFormat(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = new class ($logger, null, null, new MessageMasker($factory)) extends MessageLogger {
            protected function formatSuccess(string $requestDump, string $responseDump): string
            {
                return "REQ<{$requestDump}>RESP<{$responseDump}>";
            }

            protected function formatFailure(string $requestDump, \Throwable $error): string
            {
                return "REQ<{$requestDump}>ERR<{$error->getMessage()}>";
            }
        };

        $request = $factory->createRequest('GET', 'https://example.com/');

        $middleware->log($request, $factory->createResponse(200));
        $middleware->logFailure($request, new \RuntimeException('boom'));

        [$successRecord, $failureRecord] = [$logger->records[0]['message'], $logger->records[1]['message']];
        self::assertIsString($successRecord);
        self::assertIsString($failureRecord);
        self::assertStringStartsWith('REQ<', $successRecord);
        self::assertStringContainsString('>RESP<', $successRecord);
        self::assertStringContainsString('>ERR<', $failureRecord);
        self::assertStringContainsString('boom', $failureRecord);
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
