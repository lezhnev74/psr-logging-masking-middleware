<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Masks a PSR-7 request/response exchange and writes it as a single record
 * through an injected PSR-3 logger.
 *
 * Framework-neutral by design - it never depends on a concrete HTTP client, and
 * is not itself a client or middleware: a caller taps the exchange and calls
 * log() on success, or logFailure() when the request was sent but no response
 * came back, so a later debugger still sees exactly what was sent. The PSR-18
 * LoggingClient decorator drives it for any standard HTTP client.
 *
 * Request and response are masked independently per the configs supplied at
 * construction; a null config for either message logs that message unmasked.
 * The real messages are never mutated and their bodies are never consumed -
 * masking and serialization both read through string copies.
 *
 * Extension points. Static, once-for-all settings are constructor values: the
 * PSR-3 log level, and (on the injected MessageMasker) the redaction replacer.
 * Per-exchange decisions are protected seams a subclass overrides: shouldLog()
 * to skip an exchange, resolveRequestConfig()/resolveResponseConfig() to vary
 * the masking per message, formatSuccess()/formatFailure() to change the
 * rendered log line, and context() to shape the structured PSR-3 context array.
 */
class MessageLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?MaskingConfig $requestConfig = null,
        private readonly ?MaskingConfig $responseConfig = null,
        private readonly MessageMasker $masker = new MessageMasker(),
        private readonly MessageSerializer $serializer = new MessageSerializer(),
        private readonly string $logLevel = LogLevel::DEBUG,
    ) {
    }

    /**
     * Logs a completed exchange: the masked request and response in one record.
     * Skipped without emission when shouldLog() returns false.
     */
    public function log(RequestInterface $request, ResponseInterface $response): void
    {
        if (!$this->shouldLog($request, $response, null)) {
            return;
        }

        $maskedRequest = $this->masked($request, $this->resolveRequestConfig($request));
        $requestDump = $this->serializer->serialize($maskedRequest);
        $responseDump = $this->dump($response, $this->resolveResponseConfig($request, $response));

        $this->logger->log(
            $this->logLevel(),
            $this->formatSuccess($requestDump, $responseDump),
            $this->context($maskedRequest, $response, null),
        );
    }

    /**
     * Logs a request that was sent but produced no response: the masked request
     * is preserved and the response slot carries a note of the error's class and
     * message so the failed exchange stays debuggable. Skipped without emission
     * when shouldLog() returns false.
     */
    public function logFailure(RequestInterface $request, \Throwable $error): void
    {
        if (!$this->shouldLog($request, null, $error)) {
            return;
        }

        $maskedRequest = $this->masked($request, $this->resolveRequestConfig($request));
        $requestDump = $this->serializer->serialize($maskedRequest);

        $this->logger->log(
            $this->logLevel(),
            $this->formatFailure($requestDump, $error),
            $this->context($maskedRequest, null, $error),
        );
    }

    /**
     * PSR-3 level every record is emitted at; set once via the constructor,
     * override for a computed level.
     */
    protected function logLevel(): string
    {
        return $this->logLevel;
    }

    /**
     * Decides per exchange whether to log at all - override to skip health-checks,
     * log only failures, or sample. $response is set on success, $error on failure.
     */
    protected function shouldLog(RequestInterface $request, ?ResponseInterface $response, ?\Throwable $error): bool
    {
        return true;
    }

    /**
     * Masking config applied to the request; defaults to the constructor config,
     * override to vary masking per request (e.g. keyed on URL or method).
     */
    protected function resolveRequestConfig(RequestInterface $request): ?MaskingConfig
    {
        return $this->requestConfig;
    }

    /**
     * Masking config applied to the response; receives the request too so it can
     * be keyed on it. Defaults to the constructor config; override to vary per message.
     */
    protected function resolveResponseConfig(RequestInterface $request, ResponseInterface $response): ?MaskingConfig
    {
        return $this->responseConfig;
    }

    /**
     * Renders the log line for a completed exchange; override to change the format.
     */
    protected function formatSuccess(string $requestDump, string $responseDump): string
    {
        return "HTTP request:\n{$requestDump}\n\nHTTP response:\n{$responseDump}";
    }

    /**
     * Renders the log line for a failed exchange; receives the raw error so a
     * subclass can render it as it likes (code, class, trace). Override to change
     * the format.
     */
    protected function formatFailure(string $requestDump, \Throwable $error): string
    {
        $note = sprintf('<failed: %s: %s>', $error::class, $error->getMessage());

        return "HTTP request:\n{$requestDump}\n\nHTTP response: {$note}";
    }

    /**
     * Machine-readable record context (the PSR-3 2nd argument): method and url
     * from the masked request, plus the response status on success or the error
     * class and message on failure. Override to add or replace fields.
     *
     * The request is already masked, so url carries no redacted query secret.
     *
     * @return array<string, mixed>
     */
    protected function context(RequestInterface $maskedRequest, ?ResponseInterface $response, ?\Throwable $error): array
    {
        $context = [
            'method' => $maskedRequest->getMethod(),
            'url' => (string)$maskedRequest->getUri(),
        ];

        if ($response instanceof ResponseInterface) {
            $context['status'] = $response->getStatusCode();
        }

        if ($error instanceof \Throwable) {
            $context['error'] = sprintf('%s: %s', $error::class, $error->getMessage());
        }

        return $context;
    }

    /**
     * Serializes a message for the log, masking it per config first; a null
     * config bypasses masking and dumps the message unmasked.
     */
    protected function dump(RequestInterface|ResponseInterface $message, ?MaskingConfig $config): string
    {
        return $this->serializer->serialize($this->masked($message, $config));
    }

    /**
     * Returns the masked clone used for both the serialized dump and the context;
     * a null config bypasses masking and returns the message unchanged.
     *
     * @template T of RequestInterface|ResponseInterface
     *
     * @param  T  $message
     * @return T
     */
    private function masked(RequestInterface|ResponseInterface $message, ?MaskingConfig $config): RequestInterface|ResponseInterface
    {
        return $config instanceof MaskingConfig ? $this->masker->mask($message, $config) : $message;
    }
}
