<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use ColinODell\PsrTestLogger\TestLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\ConfiguredMasker;
use Lezhnev74\PsrLoggingMaskingMiddleware\LoggingClient;
use Lezhnev74\PsrLoggingMaskingMiddleware\Masker;
use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageLogger;
use Lezhnev74\PsrLoggingMaskingMiddleware\MessageMasker;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Capstone: one wired MessageLogger subclass composing every per-exchange seam
 * at once - resolveMasker() (Feature 3) varies the masking per URL and message,
 * shouldLog() (Feature 2) drops /health, logLevel() (Feature 1)
 * emits at info, and context() (Feature 4) adds a request_id. Driven end-to-end
 * through the PSR-18 LoggingClient decorator, on both Guzzle and Nyholm, this
 * proves the four features compose in a single subclass without touching the core.
 */
final class CapstoneCompositionTest extends PsrImplTestCase
{
    #[DataProvider('psr7Factories')]
    public function testOneWiredSubclassProducesDifferentiatedFilteredStructuredOutput(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): void {
        $logger = new TestLogger();
        $tap = $this->wireLogger($logger, $factory);

        // /health is filtered out entirely (shouldLog()).
        $this->send($factory, $tap, 'GET', 'https://api.example.com/health');
        self::assertCount(0, $logger->records, '/health must be skipped');

        // Endpoint A: its body key `token` is masked (config resolved for /a).
        $this->send(
            $factory,
            $tap,
            'POST',
            'https://api.example.com/a?api_key=secret-a',
            '{"token":"abc","name":"alice"}',
        );

        // Endpoint B: the same key is NOT masked - a different config was resolved.
        $this->send(
            $factory,
            $tap,
            'POST',
            'https://api.example.com/b?api_key=secret-b',
            '{"token":"xyz","name":"bob"}',
        );

        self::assertCount(2, $logger->records, 'both A and B log exactly one record');

        [$a, $b] = $logger->records;

        // Feature 1: every record is emitted at info, not the default debug.
        self::assertSame('info', $a['level']);
        self::assertSame('info', $b['level']);

        $messageA = $a['message'];
        $messageB = $b['message'];
        self::assertIsString($messageA);
        self::assertIsString($messageB);

        // Feature 3: differentiated masking - A masks `token`, B does not.
        self::assertStringContainsString('"token":"***"', $messageA);
        self::assertStringNotContainsString('abc', $messageA);
        self::assertStringContainsString('"token":"xyz"', $messageB);

        // Feature 4 + leak guard: structured context carries request_id, and the
        // masked url never leaks the query secret (queryNames redacts api_key).
        $contextA = $a['context'];
        $contextB = $b['context'];
        self::assertIsArray($contextA);
        self::assertIsArray($contextB);
        self::assertSame('req-1', $contextA['request_id']);
        self::assertSame('req-2', $contextB['request_id']);
        $urlA = $contextA['url'];
        self::assertIsString($urlA);
        self::assertStringNotContainsString('secret-a', $urlA);
        self::assertStringNotContainsString('secret-a', $messageA);
    }

    private function wireLogger(
        TestLogger $logger,
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
    ): MessageLogger {
        return new class ($logger, $factory) extends MessageLogger {
            private int $seq = 0;

            private readonly Masker $maskerA;

            private readonly Masker $maskerB;

            public function __construct(
                TestLogger $logger,
                StreamFactoryInterface $streams,
            ) {
                $engine = new MessageMasker($streams);
                parent::__construct($logger, ConfiguredMasker::create(MaskingConfig::create(), $engine));
                $this->maskerA = ConfiguredMasker::create(
                    MaskingConfig::create(queryNames: ['api_key'], bodyKeys: ['token']),
                    $engine,
                );
                $this->maskerB = ConfiguredMasker::create(
                    MaskingConfig::create(queryNames: ['api_key']),
                    $engine,
                );
            }

            protected function logLevel(): string
            {
                return 'info';
            }

            protected function shouldLog(RequestInterface $request, ?ResponseInterface $response, ?\Throwable $error): bool
            {
                return $request->getUri()->getPath() !== '/health';
            }

            protected function resolveMasker(RequestInterface $request, MessageInterface $message): Masker
            {
                if (!$message instanceof RequestInterface) {
                    return parent::resolveMasker($request, $message);
                }

                return $message->getUri()->getPath() === '/a' ? $this->maskerA : $this->maskerB;
            }

            /** @return array<string, mixed> */
            protected function context(RequestInterface $maskedRequest, ?ResponseInterface $response, ?\Throwable $error): array
            {
                return ['request_id' => 'req-' . ++$this->seq] + parent::context($maskedRequest, $response, $error);
            }
        };
    }

    private function send(
        RequestFactoryInterface&ResponseFactoryInterface&StreamFactoryInterface&UriFactoryInterface $factory,
        MessageLogger $tap,
        string $method,
        string $url,
        ?string $body = null,
    ): void {
        $request = $factory->createRequest($method, $url);
        if ($body !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($factory->createStream($body));
        }

        $response = $factory->createResponse(200);
        $inner = new class ($response) implements ClientInterface {
            public function __construct(private readonly ResponseInterface $response)
            {
            }

            public function sendRequest(RequestInterface $request): ResponseInterface
            {
                return $this->response;
            }
        };

        (new LoggingClient($inner, $tap))->sendRequest($request);
    }
}
