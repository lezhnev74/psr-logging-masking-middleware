<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\MessageSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Cross-impl serialization tests: the same assertions run against every PSR-7
 * implementation the harness provides, proving the serializer depends only on
 * the PSR-7 message contract and never on a concrete implementation.
 */
final class MessageSerializerTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testSerializesRequestStartLineHeadersAndBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://api.example.com/users?page=2')
            ->withHeader('Host', 'api.example.com')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"name":"ann"}'));

        $serialized = (new MessageSerializer())->serialize($request);

        self::assertSame(
            "POST /users?page=2 HTTP/1.1\r\n"
            . "Host: api.example.com\r\n"
            . "Content-Type: application/json\r\n"
            . "\r\n"
            . '{"name":"ann"}',
            $serialized,
        );
    }

    #[DataProvider('psr7Factories')]
    public function testSerializesResponseStatusLineHeadersAndBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $response = $factory->createResponse(404, 'Not Found')
            ->withHeader('Content-Type', 'text/plain')
            ->withBody($factory->createStream('missing'));

        $serialized = (new MessageSerializer())->serialize($response);

        self::assertSame(
            "HTTP/1.1 404 Not Found\r\n"
            . "Content-Type: text/plain\r\n"
            . "\r\n"
            . 'missing',
            $serialized,
        );
    }

    #[DataProvider('psr7Factories')]
    public function testRendersMultiValueHeadersOneLinePerValue(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Host', 'example.com')
            ->withHeader('Set-Cookie', ['a=1', 'b=2']);

        $serialized = (new MessageSerializer())->serialize($request);

        self::assertStringContainsString("Set-Cookie: a=1\r\n", $serialized);
        self::assertStringContainsString("Set-Cookie: b=2\r\n", $serialized);
    }

    #[DataProvider('psr7Factories')]
    public function testSerializesEmptyBodyWithTrailingBlankLine(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $response = $factory->createResponse(204);

        $serialized = (new MessageSerializer())->serialize($response);

        self::assertSame("HTTP/1.1 204 No Content\r\n\r\n", $serialized);
    }

    #[DataProvider('psr7Factories')]
    public function testRendersProtocolVersion(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withProtocolVersion('2')
            ->withHeader('Host', 'example.com');

        $serialized = (new MessageSerializer())->serialize($request);

        self::assertStringStartsWith("GET / HTTP/2\r\n", $serialized);
    }

    #[DataProvider('psr7Factories')]
    public function testDoesNotConsumeTheOriginalBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Host', 'example.com')
            ->withBody($factory->createStream('payload'));

        (new MessageSerializer())->serialize($request);

        // The caller must still be able to read the full body afterwards.
        self::assertSame('payload', (string) $request->getBody());
    }
}
