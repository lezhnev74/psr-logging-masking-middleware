<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use GuzzleHttp\Psr7\HttpFactory;
use Nyholm\Psr7\Factory\Psr17Factory;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Shared base for cross-implementation tests.
 *
 * Every masking/serialization test extends this and consumes the {@see psr7Factories}
 * data provider so the same assertions run against each provided PSR-7 implementation,
 * proving genericity. Concrete PSR-7 implementations are imported
 * here, in tests/, only - never in src/.
 */
abstract class PsrImplTestCase extends TestCase
{
    /**
     * @return array<string, array{RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface}>
     */
    public static function psr7Factories(): array
    {
        return [
            'guzzle' => [new HttpFactory()],
            'nyholm' => [new Psr17Factory()],
        ];
    }
}
