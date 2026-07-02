<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Internal PSR-7 -> string serializer.
 *
 * Keeps src/ free of any concrete PSR-7 implementation. Stateless and
 * side-effect free: the message's body is read through a string copy only,
 * never consumed - the caller's stream is left untouched.
 */
class MessageSerializer
{
    public function serialize(RequestInterface|ResponseInterface $message): string
    {
        return $this->startLine($message) . "\r\n"
            . $this->headers($message)
            . "\r\n"
            . (string) $message->getBody();
    }

    private function startLine(RequestInterface|ResponseInterface $message): string
    {
        return $message instanceof RequestInterface
            ? $this->requestLine($message)
            : $this->statusLine($message);
    }

    private function requestLine(RequestInterface $request): string
    {
        return sprintf(
            '%s %s HTTP/%s',
            $request->getMethod(),
            $request->getRequestTarget(),
            $request->getProtocolVersion(),
        );
    }

    private function statusLine(ResponseInterface $response): string
    {
        return rtrim(sprintf(
            'HTTP/%s %d %s',
            $response->getProtocolVersion(),
            $response->getStatusCode(),
            $response->getReasonPhrase(),
        ));
    }

    private function headers(MessageInterface $message): string
    {
        $lines = '';

        foreach ($message->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $lines .= sprintf("%s: %s\r\n", $name, $value);
            }
        }

        return $lines;
    }
}
