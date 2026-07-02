<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * A PSR-18 client decorator that logs every request/response through a
 * MessageLogger, with secrets masked, then delegates to the wrapped client.
 *
 * A drop-in for any PSR-18 client: the exchange is logged as one record on a
 * completed round-trip, or - when the inner client throws anything at all (not
 * just the declared ClientExceptionInterface) - the request is logged with the
 * failure noted before the throwable is re-thrown unchanged, so a sent-but-
 * failed request stays debuggable.
 */
final class LoggingClient implements ClientInterface
{
    public function __construct(
        private readonly ClientInterface $inner,
        private readonly MessageLogger $tap,
    ) {
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        try {
            $response = $this->inner->sendRequest($request);
        } catch (\Throwable $error) {
            $this->tap->logFailure($request, $error);

            throw $error;
        }

        $this->tap->log($request, $response);

        return $response;
    }
}
