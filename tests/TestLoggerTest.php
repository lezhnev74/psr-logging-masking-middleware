<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Pins the buffering PSR-3 logger used by the suite. The logging middleware
 * (not yet built) will accept any PSR-3 LoggerInterface; tests inject this
 * TestLogger, which buffers every record so the test can inspect exactly what
 * was logged - message, level and context - after driving the flow.
 */
final class TestLoggerTest extends TestCase
{
    public function testItIsAPsr3Logger(): void
    {
        self::assertInstanceOf(LoggerInterface::class, new TestLogger());
    }

    public function testItBuffersEveryRecordForInspection(): void
    {
        $logger = new TestLogger();

        $logger->info('request GET /users', ['method' => 'GET']);
        $logger->error('response 500', ['status' => 500]);

        self::assertCount(2, $logger->records);

        [$first, $second] = $logger->records;
        self::assertSame(LogLevel::INFO, $first['level']);
        self::assertSame('request GET /users', $first['message']);
        self::assertSame(['method' => 'GET'], $first['context']);
        self::assertSame(LogLevel::ERROR, $second['level']);
        self::assertSame(['status' => 500], $second['context']);
    }

    public function testAssertionHelpersInspectTheBuffer(): void
    {
        $logger = new TestLogger();

        $logger->error('response 500 Internal Server Error', ['status' => 500]);

        self::assertTrue($logger->hasErrorRecords());
        self::assertTrue($logger->hasRecordThatContains('500', LogLevel::ERROR));
        self::assertFalse($logger->hasRecordThatContains('200'));
    }
}
