<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\MessageInterface;

/**
 * The config-bound masking contract: redacts secrets from a PSR-7 message.
 *
 * Unlike the {@see MessageMasker} engine, which takes a {@see MaskingConfig}
 * on every call, an implementation of this interface carries its own masking
 * policy and exposes a single one-argument seam - the shape consumers inject
 * when they only ever mask with one fixed policy ({@see ConfiguredMasker}) or
 * with none at all ({@see NullMasker}). Implementations must return a masked
 * clone and leave the caller's message - including its body stream - untouched.
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
