<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use GuzzleHttp\Psr7\HttpFactory;
use Lezhnev74\PsrLoggingMaskingMiddleware\KeyPathMatcher;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageSerializer;
use PHPUnit\Framework\TestCase;

/**
 * Guards the extension-point engines against re-adding `final`.
 *
 * Each anonymous subclass below would be a fatal compile error if its base
 * class were `final`, so these tests lock in the classes' subclassability -
 * the prerequisite the template-method seams are built on.
 */
final class ExtensibilityTest extends TestCase
{
    public function testMessageLoggerIsSubclassable(): void
    {
        $subclass = new class (new TestLogger()) extends MessageLogger {};

        self::assertInstanceOf(MessageLogger::class, $subclass);
    }

    public function testMessageMaskerIsSubclassable(): void
    {
        $subclass = new class (new HttpFactory()) extends MessageMasker {};

        self::assertInstanceOf(MessageMasker::class, $subclass);
    }

    public function testMessageSerializerIsSubclassable(): void
    {
        $subclass = new class () extends MessageSerializer {};

        self::assertInstanceOf(MessageSerializer::class, $subclass);
    }

    public function testKeyPathMatcherIsSubclassable(): void
    {
        $subclass = new class () extends KeyPathMatcher {};

        self::assertInstanceOf(KeyPathMatcher::class, $subclass);
    }
}
