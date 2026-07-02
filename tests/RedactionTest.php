<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\Redaction;
use PHPUnit\Framework\TestCase;

final class RedactionTest extends TestCase
{
    public function testPlaceholderIsTheRedactionMarker(): void
    {
        self::assertSame('***', Redaction::PLACEHOLDER);
    }
}
