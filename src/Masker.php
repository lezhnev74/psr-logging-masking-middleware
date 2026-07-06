<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\MessageInterface;

/**
 * The masking contract: redacts secrets from a PSR-7 message.
 *
 * An implementation carries its own masking policy and exposes a single
 * one-argument seam - {@see MessageMasker} masks per a bound
 * {@see MaskingConfig}, {@see NullMasker} masks nothing. Implementations must
 * return a masked clone and leave the caller's message - including its body
 * stream - untouched.
 */
interface Masker
{
    /**
     * Returns a masked clone of the message; the original is not mutated.
     *
     * @template T of MessageInterface
     *
     * @param  T  $message
     * @return T
     */
    public function mask(MessageInterface $message): MessageInterface;
}
