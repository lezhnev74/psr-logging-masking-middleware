<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\Redaction;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Cross-impl masking tests: the same assertions run against every PSR-7
 * implementation the harness provides, proving the masker depends only on the
 * PSR-7 message contract and an injected PSR-17 stream factory - never on a
 * concrete implementation.
 */
final class MessageMaskerTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testMasksConfiguredHeaderCaseInsensitivelyAndLeavesOthers(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('Accept', 'application/json');

        $masked = (new MessageMasker($factory))->mask(
            $request,
            MaskingConfig::create(headerNames: ['authorization']),
        );

        self::assertSame(Redaction::PLACEHOLDER, $masked->getHeaderLine('Authorization'));
        self::assertSame('application/json', $masked->getHeaderLine('Accept'));
    }

    #[DataProvider('psr7Factories')]
    public function testMasksConfiguredQueryArgsInRequestsOnly(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/?token=abc&page=2');

        $masked = (new MessageMasker($factory))->mask(
            $request,
            MaskingConfig::create(queryNames: ['TOKEN']),
        );

        self::assertSame('token=' . rawurlencode(Redaction::PLACEHOLDER) . '&page=2', $masked->getUri()->getQuery());
    }

    /**
     * Table-driven test of every JSON-decode outcome in maskBody: a decoded
     * object is masked by key (recursively, incl. list elements), a valid JSON
     * scalar has no keys so it is logged verbatim, and a body that fails to
     * decode falls through to the non-loggable note. Running the whole decision
     * table through one method keeps the cases side-by-side and the boundaries
     * between them explicit - see jsonMaskingCases() for the truth table.
     *
     * @param  list<string>  $bodyKeys
     */
    #[DataProvider('jsonMaskingCasesAcrossImpls')]
    public function testMasksJsonBodyByDecodeOutcome(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
        string $body,
        array $bodyKeys,
        string $expected,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));

        $masked = (new MessageMasker($factory))->mask($request, MaskingConfig::create(bodyKeys: $bodyKeys));

        self::assertSame($expected, (string) $masked->getBody());
    }

    /**
     * The maskBody JSON truth table: [body, bodyKeys, expected masked body].
     *
     * @return array<string, array{string, list<string>, string}>
     */
    public static function jsonMaskingCases(): array
    {
        return [
            'object masked by key' => [
                '{"password":"p","keep":"v"}',
                ['password'],
                '{"password":"' . Redaction::PLACEHOLDER . '","keep":"v"}',
            ],
            'nested + list elements masked recursively' => [
                '{"password":"p","nested":{"Password":"q"},"users":[{"password":"r"}],"keep":"v"}',
                ['password'],
                '{"password":"' . Redaction::PLACEHOLDER . '","nested":{"Password":"' . Redaction::PLACEHOLDER
                    . '"},"users":[{"password":"' . Redaction::PLACEHOLDER . '"}],"keep":"v"}',
            ],
            'valid scalar has no keys, logged verbatim' => [
                '"password"',
                ['password'],
                '"password"',
            ],
            'valid null logged verbatim' => [
                'null',
                ['password'],
                'null',
            ],
            'unparsable JSON falls through to note' => [
                'not json',
                ['x'],
                '<non-loggable application/json body: 8 bytes>',
            ],
            'dot-path with * masks each list element, leaves top-level key' => [
                '{"password":"top","users":[{"password":"a"},{"password":"b"}]}',
                ['users.*.password'],
                '{"password":"top","users":[{"password":"' . Redaction::PLACEHOLDER
                    . '"},{"password":"' . Redaction::PLACEHOLDER . '"}]}',
            ],
            'deep ** path masks token at every depth' => [
                '{"token":"a","nested":{"deep":{"token":"b"}},"keep":"v"}',
                ['**.token'],
                '{"token":"' . Redaction::PLACEHOLDER . '","nested":{"deep":{"token":"'
                    . Redaction::PLACEHOLDER . '"}},"keep":"v"}',
            ],
            'exact path masks only that location' => [
                '{"a":{"b":{"c":"x"}},"c":"keep"}',
                ['a.b.c'],
                '{"a":{"b":{"c":"' . Redaction::PLACEHOLDER . '"}},"c":"keep"}',
            ],
        ];
    }

    /**
     * Cross-product of every PSR-7 impl with every JSON truth-table case, so the
     * table runs on Guzzle and Nyholm alike.
     *
     * @return array<string, array{RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface, string, list<string>, string}>
     */
    public static function jsonMaskingCasesAcrossImpls(): array
    {
        $combined = [];
        foreach (self::psr7Factories() as $impl => [$factory]) {
            foreach (self::jsonMaskingCases() as $name => $case) {
                $combined["{$impl}: {$name}"] = [$factory, ...$case];
            }
        }

        return $combined;
    }

    #[DataProvider('psr7Factories')]
    public function testTreatsJsonSuffixMediaTypeAsJsonIgnoringParameters(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/vnd.api+json; charset=utf-8')
            ->withBody($factory->createStream('{"token":"x"}'));

        $masked = (new MessageMasker($factory))->mask(
            $request,
            MaskingConfig::create(bodyKeys: ['token']),
        );

        self::assertSame(['token' => Redaction::PLACEHOLDER], json_decode((string) $masked->getBody(), true));
    }

    #[DataProvider('psr7Factories')]
    public function testMasksFormUrlEncodedBodyByFieldCaseInsensitivelyKeepingFlags(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('Secret=abc&keep=1&flag'));

        $masked = (new MessageMasker($factory))->mask(
            $request,
            MaskingConfig::create(bodyKeys: ['secret']),
        );

        self::assertSame(
            'Secret=' . rawurlencode(Redaction::PLACEHOLDER) . '&keep=1&flag',
            (string) $masked->getBody(),
        );
    }

    #[DataProvider('psr7Factories')]
    public function testReplacesOpaqueBodyWithNonLoggableNote(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withBody($factory->createStream('binarydata!'));

        $masked = (new MessageMasker($factory))->mask($request, MaskingConfig::create());

        self::assertSame('<non-loggable application/octet-stream body: 11 bytes>', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testReplacesOpaqueBodyWithoutContentTypeWithNote(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $response = $factory->createResponse(200)
            ->withBody($factory->createStream('plain'));

        $masked = (new MessageMasker($factory))->mask($response, MaskingConfig::create());

        self::assertSame('<non-loggable body: 5 bytes>', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testKeepsEmptyBodyEmpty(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Content-Type', 'application/octet-stream');

        $masked = (new MessageMasker($factory))->mask($request, MaskingConfig::create());

        self::assertSame('', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testDoesNotMutateTheOriginalMessage(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/?token=abc')
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p"}'));

        (new MessageMasker($factory))->mask(
            $request,
            MaskingConfig::create(headerNames: ['authorization'], queryNames: ['token'], bodyKeys: ['password']),
        );

        self::assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        self::assertSame('token=abc', $request->getUri()->getQuery());
        self::assertSame('{"password":"p"}', (string) $request->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testDoesNotConsumeTheOriginalBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p"}'));

        (new MessageMasker($factory))->mask(
            $request,
            MaskingConfig::create(bodyKeys: ['password']),
        );

        self::assertSame('{"password":"p"}', (string) $request->getBody());
    }
}
