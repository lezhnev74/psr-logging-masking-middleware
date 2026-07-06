<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

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
 * Tests for the Masker contract: MessageMasker is its canonical config-bound
 * implementation behind the one-argument mask() seam, and NullMasker is the
 * no-op implementation of the same contract.
 */
final class MaskerContractTest extends PsrImplTestCase
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
        $masker = new MessageMasker(
            MaskingConfig::create(['Authorization'], ['token'], ['password']),
            $factory,
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

    public function testConstructorFallsBackToDiscoveredStreamFactory(): void
    {
        // A guzzlehttp/psr7 factory is installed, so PSR-17 discovery succeeds
        // and the masker is usable with only a config.
        self::assertInstanceOf(
            Masker::class,
            new MessageMasker(MaskingConfig::create()),
        );
    }
}
