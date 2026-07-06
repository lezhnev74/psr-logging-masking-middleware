<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\ConfiguredMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\Masker;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\NullMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Tests for the config-bound Masker contract: ConfiguredMasker binds one
 * MaskingConfig to a MessageMasker engine behind the one-argument mask()
 * seam, and NullMasker is the no-op implementation of the same contract.
 */
final class ConfiguredMaskerTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testNullMaskerPassesTheMessageThroughUnchanged(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/x')
            ->withHeader('Authorization', 'Bearer secret');

        self::assertSame($request, (new NullMasker())->mask($request));
    }

    #[DataProvider('psr7Factories')]
    public function testMasksBoundConfigAcrossHeaderQueryAndBodyAndClones(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $masker = ConfiguredMasker::create(
            MaskingConfig::create(['Authorization'], ['token'], ['password']),
            new MessageMasker($factory),
        );
        self::assertInstanceOf(Masker::class, $masker);

        $request = $factory->createRequest('POST', 'https://example.com/login?token=abc123')
            ->withHeader('Authorization', 'Bearer topsecret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"hunter2","user":"bob"}'));

        $masked = $masker->mask($request);

        self::assertSame('***', $masked->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('abc123', $masked->getUri()->getQuery());
        self::assertStringNotContainsString('hunter2', (string) $masked->getBody());
        self::assertStringContainsString('bob', (string) $masked->getBody());

        // The caller's message and body are untouched.
        self::assertSame('Bearer topsecret', $request->getHeaderLine('Authorization'));
        self::assertSame('{"password":"hunter2","user":"bob"}', (string) $request->getBody());
    }

    public function testCreateFallsBackToADefaultEngine(): void
    {
        // A guzzlehttp/psr7 factory is installed, so PSR-17 discovery succeeds
        // and the default engine is usable with no wiring.
        self::assertInstanceOf(
            ConfiguredMasker::class,
            ConfiguredMasker::create(MaskingConfig::create()),
        );
    }
}
