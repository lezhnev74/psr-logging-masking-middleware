<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\MessageInterface;

/**
 * The no-op {@see Masker}: every message passes through unchanged.
 *
 * The explicit no-masking choice wherever a Masker is required - e.g.
 * `new MessageLogger($logger, new NullMasker())` logs exchanges verbatim.
 */
final class NullMasker implements Masker
{
    public function mask(MessageInterface $message): MessageInterface
    {
        return $message;
    }
}
