<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

use Psr\Http\Message\MessageInterface;

/**
 * The single argument handed to a replacer closure: everything known about one
 * matched, about-to-be-redacted location. A named-field carrier rather than a
 * bag of positional strings, so a closure reads `$t->value` / `$t->path` with no
 * argument-order risk and the contract can grow a field without breaking callers.
 *
 * The closure is invoked only at scalar leaves: `value` is the current value
 * coerced to string (int/float/bool/null cast the PHP way - `false`/`null`
 * become `''`). A matched array/object node is redacted wholesale by the
 * placeholder and never reaches a closure, so `value` is always a real scalar.
 */
final class MaskTarget
{
    public function __construct(
        public readonly MessageInterface $message,
        public readonly MaskKind $kind,
        public readonly string $path,
        public readonly string $value,
    ) {
    }
}
