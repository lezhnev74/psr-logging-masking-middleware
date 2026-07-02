<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * Adapts a MessageLogger into a handler-stack middleware closure - the shape
 * Guzzle (and any client using the same "handler that returns a thenable"
 * convention) expects on its HandlerStack.
 *
 * This stays framework-neutral: it never imports GuzzleHttp\*, so the package
 * keeps a single PSR-only dependency surface for Guzzle and non-Guzzle users
 * alike. The returned middleware is a plain callable(callable): callable; the
 * inner handler's return value is treated as a thenable (an object exposing
 * then(onFulfilled, onRejected)), which is exactly Guzzle's PromiseInterface.
 * The exchange is tapped through MessageLogger: log() on a resolved response,
 * logFailure() on a rejected transfer, and the original outcome propagates
 * unchanged.
 *
 * Guzzle wiring is a one-liner:
 *
 *     $stack->push(HandlerMiddleware::for($messageLogger));
 */
final class HandlerMiddleware
{
    /**
     * Returns a handler-stack middleware that taps every exchange through the
     * given logger. Push the result onto a Guzzle HandlerStack (or any stack
     * following the same handler convention).
     *
     * @return callable(callable(RequestInterface, array<string, mixed>): object): (callable(RequestInterface, array<string, mixed>): object)
     */
    public static function for(MessageLogger $logger): callable
    {
        return static fn (callable $handler): callable =>
            /**
             * @param  array<string, mixed>  $options
             */
            static function (RequestInterface $request, array $options) use ($handler, $logger): object {
                $promise = $handler($request, $options);

                // The handler returns a duck-typed promise (e.g. Guzzle's
                // PromiseInterface). src/ must stay implementation-agnostic, so
                // the type is left open and the ->then() seam is trusted here.
                // @phpstan-ignore method.nonObject, return.type
                return $promise->then(
                    static function (ResponseInterface $response) use ($request, $logger): ResponseInterface {
                        $logger->log($request, $response);

                        return $response;
                    },
                    static function (\Throwable $reason) use ($request, $logger): mixed {
                        $logger->logFailure($request, $reason);

                        throw $reason;
                    },
                );
            };
    }
}
