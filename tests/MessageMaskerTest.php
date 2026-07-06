<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\KeyPathMatcher;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskKind;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskTarget;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\MessageInterface;
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

        $masked = (new MessageMasker(MaskingConfig::create(headerNames: ['authorization']), $factory))
            ->mask($request);

        self::assertSame('***', $masked->getHeaderLine('Authorization'));
        self::assertSame('application/json', $masked->getHeaderLine('Accept'));
    }

    #[DataProvider('psr7Factories')]
    public function testFixedStringReplacerReplacesDefaultAcrossHeaderQueryAndBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // A different fixed marker is now expressed as a constant replacer -
        // there is no dedicated placeholder constructor argument.
        $marker = '[REDACTED]';
        $request = $factory->createRequest('POST', 'https://example.com/?token=abc')
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"hunter2"}'));

        $masked = (new MessageMasker(
            MaskingConfig::create(headerNames: ['Authorization'], queryNames: ['token'], bodyKeys: ['password']),
            $factory,
            replacer: static fn (MaskTarget $t): string => $marker,
        ))->mask($request);

        self::assertSame($marker, $masked->getHeaderLine('Authorization'));
        self::assertSame('token=' . rawurlencode($marker), $masked->getUri()->getQuery());
        self::assertSame('{"password":"' . $marker . '"}', (string) $masked->getBody());
        self::assertStringNotContainsString('***', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testSubclassExtendsBodyMaskingViaProtectedSeams(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // A subclass adds XML handling by overriding the now-protected maskBody
        // and still delegates every other type to parent::nonLoggableNote().
        $masker = new class (MaskingConfig::create(), $factory) extends MessageMasker {
            public function maskBody(
                string $body,
                string $contentType,
                MessageInterface $message,
            ): string {
                if (str_starts_with($contentType, 'application/xml')) {
                    return '<xml redacted>';
                }

                return parent::maskBody($body, $contentType, $message);
            }
        };

        $xml = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/xml')
            ->withBody($factory->createStream('<user><pass>hunter2</pass></user>'));
        $binary = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withBody($factory->createStream('rawbytes'));

        self::assertSame('<xml redacted>', (string) $masker->mask($xml)->getBody());
        self::assertStringNotContainsString('hunter2', (string) $masker->mask($xml)->getBody());
        // Unknown type still falls through to the inherited size note.
        self::assertSame(
            '<non-loggable application/octet-stream body: 8 bytes>',
            (string) $masker->mask($binary)->getBody(),
        );
    }

    #[DataProvider('psr7Factories')]
    public function testPreserveUnknownBodiesKeepsOpaqueBodyByteForByte(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // With preserveUnknownBodies the engine records an opaque body
        // faithfully while named header/query/JSON-key redactions still apply.
        $config = MaskingConfig::create(['Authorization'], ['token'], ['password']);
        $masker = new MessageMasker($config, $factory, preserveUnknownBodies: true);
        $binary = "bin\x00\r\n\r\n\xfe\xff";

        $opaque = $factory->createRequest('POST', 'https://example.com/?token=abc123')
            ->withHeader('Authorization', 'Bearer topsecret')
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withBody($factory->createStream($binary));

        $masked = $masker->mask($opaque);
        self::assertSame($binary, (string) $masked->getBody());
        self::assertSame('***', $masked->getHeaderLine('Authorization'));
        self::assertStringNotContainsString('abc123', $masked->getUri()->getQuery());

        // Known types stay masked by key; the flag only affects unknown types.
        $json = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p"}'));
        self::assertSame('{"password":"***"}', (string) $masker->mask($json)->getBody());

        // A body without a Content-Type is opaque too and is kept verbatim.
        $untyped = $factory->createRequest('POST', 'https://example.com/')
            ->withBody($factory->createStream('plain'));
        self::assertSame('plain', (string) $masker->mask($untyped)->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testSubclassAddsContentTypeViaMaskUnknownTypeSeam(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // A subclass handles application/xml through the maskUnknownType() seam -
        // the primary override point - while every known type and every other
        // unknown type keep the inherited behaviour untouched.
        $masker = new class (MaskingConfig::create(bodyKeys: ['password']), $factory) extends MessageMasker {
            protected function maskUnknownType(string $type, string $body): string
            {
                if ($type === 'application/xml') {
                    return '<xml redacted>';
                }

                return parent::maskUnknownType($type, $body);
            }
        };

        $xml = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/xml; charset=utf-8')
            ->withBody($factory->createStream('<user><pass>hunter2</pass></user>'));
        $json = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p"}'));
        $binary = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/octet-stream')
            ->withBody($factory->createStream('rawbytes'));

        // Media-type parameters are normalised away before the seam sees the type.
        self::assertSame('<xml redacted>', (string) $masker->mask($xml)->getBody());
        self::assertStringNotContainsString('hunter2', (string) $masker->mask($xml)->getBody());
        // Known JSON still masked by key via the dispatch table.
        self::assertSame('{"password":"***"}', (string) $masker->mask($json)->getBody());
        // Other unknown types still fall through to the inherited size note.
        self::assertSame(
            '<non-loggable application/octet-stream body: 8 bytes>',
            (string) $masker->mask($binary)->getBody(),
        );
    }

    #[DataProvider('psr7Factories')]
    public function testSubclassOverridesDispatchTableViaMaskBodyByTypeSeam(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // Overriding maskBodyByType() lets a subclass reroute a known type; here
        // JSON is diverted to a custom note, proving the dispatch seam is reachable.
        $masker = new class (MaskingConfig::create(), $factory) extends MessageMasker {
            protected function maskBodyByType(
                string $type,
                string $body,
                MessageInterface $message,
            ): string {
                if ($type === 'application/json') {
                    return '<json diverted>';
                }

                return parent::maskBodyByType($type, $body, $message);
            }
        };

        $json = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p"}'));

        self::assertSame('<json diverted>', (string) $masker->mask($json)->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testInjectedKeyPathMatcherWinsOverDefault(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // A custom matcher subclass redacts an extra key ("secret") that the
        // configured bodyKeys never list - proving the injected matcher, not the
        // default `new KeyPathMatcher()`, drives body-key matching.
        $matcher = new class () extends KeyPathMatcher {
            protected function matchesKey(string $key, array $path): bool
            {
                if ($path !== [] && strcasecmp('secret', $path[count($path) - 1]) === 0) {
                    return true;
                }

                return parent::matchesKey($key, $path);
            }
        };

        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"secret":"s","password":"p","keep":"v"}'));

        $masked = (new MessageMasker(MaskingConfig::create(bodyKeys: ['password']), $factory, pathMatcher: $matcher))
            ->mask($request);

        self::assertSame(
            '{"secret":"***","password":"***","keep":"v"}',
            (string) $masked->getBody(),
        );
    }

    #[DataProvider('psr7Factories')]
    public function testMasksConfiguredQueryArgsInRequestsOnly(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/?token=abc&page=2');

        $masked = (new MessageMasker(MaskingConfig::create(queryNames: ['TOKEN']), $factory))
            ->mask($request);

        self::assertSame('token=' . rawurlencode('***') . '&page=2', $masked->getUri()->getQuery());
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

        $masked = (new MessageMasker(MaskingConfig::create(bodyKeys: $bodyKeys), $factory))->mask($request);

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
                '{"password":"***","keep":"v"}',
            ],
            'nested + list elements masked recursively' => [
                '{"password":"p","nested":{"Password":"q"},"users":[{"password":"r"}],"keep":"v"}',
                ['password'],
                '{"password":"***","nested":{"Password":"***'
                    . '"},"users":[{"password":"***"}],"keep":"v"}',
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
                '{"password":"top","users":[{"password":"***'
                    . '"},{"password":"***"}]}',
            ],
            'deep ** path masks token at every depth' => [
                '{"token":"a","nested":{"deep":{"token":"b"}},"keep":"v"}',
                ['**.token'],
                '{"token":"***","nested":{"deep":{"token":"'
                    . '***"}},"keep":"v"}',
            ],
            'exact path masks only that location' => [
                '{"a":{"b":{"c":"x"}},"c":"keep"}',
                ['a.b.c'],
                '{"a":{"b":{"c":"***"}},"c":"keep"}',
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

        $masked = (new MessageMasker(MaskingConfig::create(bodyKeys: ['token']), $factory))
            ->mask($request);

        self::assertSame(['token' => '***'], json_decode((string) $masked->getBody(), true));
    }

    #[DataProvider('psr7Factories')]
    public function testMasksFormUrlEncodedBodyByFieldCaseInsensitivelyKeepingFlags(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
            ->withBody($factory->createStream('Secret=abc&keep=1&flag'));

        $masked = (new MessageMasker(MaskingConfig::create(bodyKeys: ['secret']), $factory))
            ->mask($request);

        self::assertSame(
            'Secret=' . rawurlencode('***') . '&keep=1&flag',
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

        $masked = (new MessageMasker(MaskingConfig::create(), $factory))->mask($request);

        self::assertSame('<non-loggable application/octet-stream body: 11 bytes>', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testReplacesOpaqueBodyWithoutContentTypeWithNote(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $response = $factory->createResponse(200)
            ->withBody($factory->createStream('plain'));

        $masked = (new MessageMasker(MaskingConfig::create(), $factory))->mask($response);

        self::assertSame('<non-loggable body: 5 bytes>', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testKeepsEmptyBodyEmpty(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('GET', 'https://example.com/')
            ->withHeader('Content-Type', 'application/octet-stream');

        $masked = (new MessageMasker(MaskingConfig::create(), $factory))->mask($request);

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

        (new MessageMasker(
            MaskingConfig::create(headerNames: ['authorization'], queryNames: ['token'], bodyKeys: ['password']),
            $factory,
        ))->mask($request);

        self::assertSame('Bearer secret', $request->getHeaderLine('Authorization'));
        self::assertSame('token=abc', $request->getUri()->getQuery());
        self::assertSame('{"password":"p"}', (string) $request->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testReplacerReceivesKindPathAndValueForEverySelectedLeaf(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // A spying replacer records the (kind, path, value) tuple at every matched
        // scalar leaf across a header, a query arg, a form-style field and nested
        // JSON incl. a list index - proving each surface reports its own kind/path.
        $seen = [];
        $spy = function (MaskTarget $t) use (&$seen): string {
            $seen[] = [$t->kind->value, $t->path, $t->value];

            return '***';
        };

        $request = $factory->createRequest('POST', 'https://example.com/?token=abc')
            ->withHeader('Authorization', 'Bearer secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p","data":[{"name":"n"}]}'));

        (new MessageMasker(
            MaskingConfig::create(
                headerNames: ['Authorization'],
                queryNames: ['token'],
                bodyKeys: ['password', 'data.*.name'],
            ),
            $factory,
            replacer: $spy,
        ))->mask($request);

        self::assertContains(['query', 'token', 'abc'], $seen);
        self::assertContains(['header', 'Authorization', 'Bearer secret'], $seen);
        self::assertContains(['body', 'password', 'p'], $seen);
        self::assertContains(['body', 'data.0.name', 'n'], $seen);
    }

    #[DataProvider('psr7Factories')]
    public function testCustomReplacerComputesReplacementFromValueAtEachLocation(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // Keep only the last char, star the rest - a format-preserving replacer
        // exercised across header, query and JSON body at once.
        $lastChar = static fn (MaskTarget $t): string
            => str_repeat('*', max(0, strlen($t->value) - 1)) . substr($t->value, -1);

        $request = $factory->createRequest('POST', 'https://example.com/?token=abcd')
            ->withHeader('Authorization', 'secret')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"hunter2"}'));

        $masked = (new MessageMasker(
            MaskingConfig::create(headerNames: ['Authorization'], queryNames: ['token'], bodyKeys: ['password']),
            $factory,
            replacer: $lastChar,
        ))->mask($request);

        self::assertSame('*****t', $masked->getHeaderLine('Authorization'));
        self::assertSame('token=' . rawurlencode('***d'), $masked->getUri()->getQuery());
        self::assertSame('{"password":"******2"}', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testLocationAwareReplacerKeysOnKindAndPath(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // Hash the Authorization header, star everything else - the replacer keys
        // its decision on kind + path, not just the value.
        $replacer = static fn (MaskTarget $t): string
            => $t->kind === MaskKind::Header && strcasecmp($t->path, 'Authorization') === 0
                ? 'sha256:' . substr(hash('sha256', $t->value), 0, 8)
                : '***';

        $request = $factory->createRequest('GET', 'https://example.com/?token=abc')
            ->withHeader('Authorization', 'Bearer secret');

        $masked = (new MessageMasker(
            MaskingConfig::create(headerNames: ['Authorization'], queryNames: ['token']),
            $factory,
            replacer: $replacer,
        ))->mask($request);

        self::assertSame('sha256:' . substr(hash('sha256', 'Bearer secret'), 0, 8), $masked->getHeaderLine('Authorization'));
        self::assertSame('token=' . rawurlencode('***'), $masked->getUri()->getQuery());
    }

    /**
     * The replacer runs only at scalar leaves: a matched array/object node is
     * redacted wholesale by the placeholder and never reaches the closure, while a
     * matched non-string scalar reaches it string-cast. The table pairs each JSON
     * leaf type with the value the replacer is expected to receive (or a sentinel
     * for "closure not called - wholesale placeholder instead").
     *
     * @param  list<string>  $bodyKeys
     */
    #[DataProvider('coercionCasesAcrossImpls')]
    public function testReplacerSeesScalarLeavesCoercedAndSkipsContainers(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
        string $body,
        array $bodyKeys,
        ?string $expectedValueSeen,
        string $expectedBody,
    ): void {
        $seen = [];
        $spy = function (MaskTarget $t) use (&$seen): string {
            $seen[] = $t->value;

            return 'R';
        };

        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream($body));

        $masked = (new MessageMasker(MaskingConfig::create(bodyKeys: $bodyKeys), $factory, replacer: $spy))
            ->mask($request);

        if ($expectedValueSeen === null) {
            self::assertSame([], $seen, 'container match must not invoke the replacer');
        } else {
            self::assertSame([$expectedValueSeen], $seen);
        }
        self::assertSame($expectedBody, (string) $masked->getBody());
    }

    /**
     * Value-coercion truth table: [body, bodyKeys, value the replacer should see
     * (null = not called), expected masked body]. The replacer returns "R" for a
     * scalar; a container is redacted wholesale by the default placeholder "***".
     *
     * @return array<string, array{string, list<string>, ?string, string}>
     */
    public static function coercionCases(): array
    {
        return [
            'string scalar passed as-is' => ['{"k":"v"}', ['k'], 'v', '{"k":"R"}'],
            'int scalar string-cast' => ['{"k":42}', ['k'], '42', '{"k":"R"}'],
            'float scalar string-cast' => ['{"k":1.5}', ['k'], '1.5', '{"k":"R"}'],
            'true -> "1"' => ['{"k":true}', ['k'], '1', '{"k":"R"}'],
            'false -> ""' => ['{"k":false}', ['k'], '', '{"k":"R"}'],
            'null -> ""' => ['{"k":null}', ['k'], '', '{"k":"R"}'],
            'object node redacted wholesale, closure not called' => [
                '{"k":{"a":1,"b":2}}',
                ['k'],
                null,
                '{"k":"***"}',
            ],
            'array node redacted wholesale, closure not called' => [
                '{"k":[1,2,3]}',
                ['k'],
                null,
                '{"k":"***"}',
            ],
        ];
    }

    /**
     * @return array<string, array{RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface, string, list<string>, ?string, string}>
     */
    public static function coercionCasesAcrossImpls(): array
    {
        $combined = [];
        foreach (self::psr7Factories() as $impl => [$factory]) {
            foreach (self::coercionCases() as $name => $case) {
                $combined["{$impl}: {$name}"] = [$factory, ...$case];
            }
        }

        return $combined;
    }

    #[DataProvider('psr7Factories')]
    public function testReplacerDecidesScalarLeavesWhileContainersTakeDefaultPlaceholder(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        // The replacer decides scalar leaves; a wholesale-redacted container node
        // never runs the replacer and so takes the default '***' marker.
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"scalar":"s","obj":{"a":1}}'));

        $masked = (new MessageMasker(
            MaskingConfig::create(bodyKeys: ['scalar', 'obj']),
            $factory,
            replacer: static fn (MaskTarget $t): string => 'FROM_CLOSURE',
        ))->mask($request);

        self::assertSame('{"scalar":"FROM_CLOSURE","obj":"***"}', (string) $masked->getBody());
    }

    #[DataProvider('psr7Factories')]
    public function testDoesNotConsumeTheOriginalBody(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $request = $factory->createRequest('POST', 'https://example.com/')
            ->withHeader('Content-Type', 'application/json')
            ->withBody($factory->createStream('{"password":"p"}'));

        (new MessageMasker(MaskingConfig::create(bodyKeys: ['password']), $factory))->mask($request);

        self::assertSame('{"password":"p"}', (string) $request->getBody());
    }
}
