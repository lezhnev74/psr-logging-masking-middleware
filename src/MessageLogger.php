<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\LoggerInterface;

/**
 * Masks a PSR-7 request/response exchange and writes it as a single debug
 * record through an injected PSR-3 logger.
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
 */
final class MessageLogger
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly ?MaskingConfig $requestConfig = null,
        private readonly ?MaskingConfig $responseConfig = null,
        private readonly MessageMasker $masker = new MessageMasker(),
        private readonly MessageSerializer $serializer = new MessageSerializer(),
    ) {
    }

    /**
     * Logs a completed exchange: the masked request and response in one debug record.
     */
    public function log(RequestInterface $request, ResponseInterface $response): void
    {
        $requestDump = $this->dump($request, $this->requestConfig);
        $responseDump = $this->dump($response, $this->responseConfig);

        $this->logger->debug("HTTP request:\n{$requestDump}\n\nHTTP response:\n{$responseDump}");
    }

    /**
     * Logs a request that was sent but produced no response: the masked request
     * is preserved and the response slot carries a note of the error's class and
     * message so the failed exchange stays debuggable.
     */
    public function logFailure(RequestInterface $request, \Throwable $error): void
    {
        $requestDump = $this->dump($request, $this->requestConfig);
        $note = sprintf('<failed: %s: %s>', $error::class, $error->getMessage());

        $this->logger->debug("HTTP request:\n{$requestDump}\n\nHTTP response: {$note}");
    }

    /**
     * Serializes a message for the log, masking it per config first; a null
     * config bypasses masking and dumps the message unmasked.
     */
    private function dump(RequestInterface|ResponseInterface $message, ?MaskingConfig $config): string
    {
        $message = $config instanceof MaskingConfig ? $this->masker->mask($message, $config) : $message;

        return $this->serializer->serialize($message);
    }
}
