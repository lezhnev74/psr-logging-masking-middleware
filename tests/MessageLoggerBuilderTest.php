<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskTarget;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLoggerBuilder;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;
use Psr\Log\LogLevel;

/**
 * Cross-impl tests for the fluent builder: the same assertions run against every
 * PSR-7 implementation, proving the assembled MessageLogger masks and logs
 * identically to a hand-wired one, whichever impl builds the messages.
 */
final class MessageLoggerBuilderTest extends PsrImplTestCase
{
    public function testForBuildsMessageLogger(): void
    {
        $builder = MessageLoggerBuilder::for(new TestLogger());

        self::assertInstanceOf(MessageLogger::class, $builder->build());
    }

    #[DataProvider('psr7Factories')]
    public function testEmptyBuilderLogsUnmasked(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)->streamFactory($factory)->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('super-secret', $message);
        self::assertStringNotContainsString('***', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testWithMaskingConfigMasksAccordingToConfig(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(['Authorization'], ['api_key'], ['password']))
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('POST', 'https://api.example.com/login?api_key=abc&page=2')
            ->withHeader('Authorization', 'Bearer super-secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"hunter2","user":"ann"}'));

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringContainsString('api_key=' . rawurlencode('***'), $message);
        self::assertStringContainsString('"password":"***"', $message);
        self::assertStringContainsString('page=2', $message);
        self::assertStringContainsString('"user":"ann"', $message);
        self::assertStringNotContainsString('super-secret', $message);
        self::assertStringNotContainsString('hunter2', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testWithMaskingConfigCalledTwiceMerges(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(['Authorization']))
            ->withMaskingConfig(MaskingConfig::create(['X-Api-Token']))
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer aaa')
            ->withHeader('X-Api-Token', 'bbb');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringContainsString('X-Api-Token: ***', $message);
        self::assertStringNotContainsString('aaa', $message);
        self::assertStringNotContainsString('bbb', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testPlaceholderOverridesDefaultMarker(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(['Authorization']))
            ->placeholder('[redacted]')
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: [redacted]', $message);
        self::assertStringNotContainsString('***', $message);
        self::assertStringNotContainsString('super-secret', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testReplaceWithReceivesMaskTarget(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(['Authorization']))
            ->replaceWith(static fn (MaskTarget $target): string => strtoupper($target->kind->name))
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: HEADER', $message);
        self::assertStringNotContainsString('super-secret', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testPlaceholderThenReplaceWithLastWins(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(['Authorization']))
            ->placeholder('FROM_PLACEHOLDER')
            ->replaceWith(static fn (MaskTarget $target): string => 'FROM_CLOSURE')
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer x');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: FROM_CLOSURE', $message);
        self::assertStringNotContainsString('FROM_PLACEHOLDER', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testReplaceWithThenPlaceholderLastWins(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(['Authorization']))
            ->replaceWith(static fn (MaskTarget $target): string => 'FROM_CLOSURE')
            ->placeholder('FROM_PLACEHOLDER')
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer x');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: FROM_PLACEHOLDER', $message);
        self::assertStringNotContainsString('FROM_CLOSURE', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testLogLevelIsApplied(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->logLevel(LogLevel::INFO)
            ->streamFactory($factory)
            ->build();

        $middleware->log($factory->createRequest('GET', 'https://example.com/'), $factory->createResponse(200));

        self::assertSame(LogLevel::INFO, $logger->records[0]['level']);
    }

    #[DataProvider('psr7Factories')]
    public function testLogLevelDefaultsToDebug(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)->streamFactory($factory)->build();

        $middleware->log($factory->createRequest('GET', 'https://example.com/'), $factory->createResponse(200));

        self::assertSame(LogLevel::DEBUG, $logger->records[0]['level']);
    }

    #[DataProvider('psr7Factories')]
    public function testInjectedStreamFactoryMasksJsonBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $middleware = MessageLoggerBuilder::for($logger)
            ->withMaskingConfig(MaskingConfig::create(bodyKeys: ['password']))
            ->streamFactory($factory)
            ->build();

        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"hunter2","user":"ann"}'));

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('"password":"***"', $message);
        self::assertStringContainsString('"user":"ann"', $message);
        self::assertStringNotContainsString('hunter2', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testNoStreamFactoryFallsBackToDiscovery(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        // No streamFactory() call: the masker must discover a PSR-17 factory itself.
        $middleware = MessageLoggerBuilder::for($logger)->withMaskingConfig(MaskingConfig::create(['Authorization']))->build();

        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer super-secret');

        $middleware->log($request, $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('Authorization: ***', $message);
        self::assertStringNotContainsString('super-secret', $message);
    }

    #[DataProvider('psr7Factories')]
    public function testSerializerOverrideIsUsed(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $serializer = new class () extends MessageSerializer {
            public function serialize(RequestInterface|\Psr\Http\Message\ResponseInterface $message): string
            {
                return '<<custom-serializer>>';
            }
        };
        $middleware = MessageLoggerBuilder::for($logger)
            ->serializer($serializer)
            ->streamFactory($factory)
            ->build();

        $middleware->log($factory->createRequest('GET', 'https://example.com/'), $factory->createResponse(200));

        $message = $logger->records[0]['message'];
        self::assertIsString($message);
        self::assertStringContainsString('<<custom-serializer>>', $message);
    }
}
