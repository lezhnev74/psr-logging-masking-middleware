<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Self-tests for the cross-impl harness itself: prove {@see PsrImplTestCase::psr7Factories}
 * hands later pieces genuinely distinct implementations that satisfy every PSR-17
 * factory interface in use - so the intersection type is enforced by exercised calls,
 * not merely asserted in a docblock.
 */
final class PsrImplHarnessTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testFactoryImplementsEveryPsr17InterfaceInUse(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        self::assertSame('GET', $factory->createRequest('GET', '/')->getMethod());
        self::assertSame(204, $factory->createResponse(204)->getStatusCode());
        self::assertSame('body', (string) $factory->createStream('body'));
        self::assertSame('/path', $factory->createUri('/path')->getPath());
    }

    public function testProviderRowsAreDistinctImplementations(): void
    {
        $classes = array_map(
            static fn (array $row): string => $row[0]::class,
            self::psr7Factories(),
        );

        self::assertSame(
            array_values($classes),
            array_values(array_unique($classes)),
            'Each cross-impl row must be a distinct PSR-7 implementation.',
        );
        self::assertGreaterThanOrEqual(2, count($classes), 'Harness must cover at least two impls.');
    }
}
