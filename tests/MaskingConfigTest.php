<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use PHPUnit\Framework\TestCase;

/**
 * MaskingConfig is impl-agnostic data, so it uses the plain TestCase - the
 * cross-impl PsrImplTestCase base is reserved for masking/serialization pieces.
 */
final class MaskingConfigTest extends TestCase
{
    public function testCreateStoresTheThreeLists(): void
    {
        $config = MaskingConfig::create(
            headerNames: ['Authorization', 'Cookie'],
            queryNames: ['token', 'api_key'],
            bodyKeys: ['password', 'secret'],
        );

        self::assertSame(['Authorization', 'Cookie'], $config->headerNames);
        self::assertSame(['token', 'api_key'], $config->queryNames);
        self::assertSame(['password', 'secret'], $config->bodyKeys);
    }

    public function testCreateDefaultsToEmptyLists(): void
    {
        $config = MaskingConfig::create();

        self::assertSame([], $config->headerNames);
        self::assertSame([], $config->queryNames);
        self::assertSame([], $config->bodyKeys);
    }

    public function testPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(MaskingConfig::class);

        foreach (['headerNames', 'queryNames', 'bodyKeys'] as $property) {
            self::assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                "Property {$property} must be readonly (immutable value object).",
            );
        }
    }
}
