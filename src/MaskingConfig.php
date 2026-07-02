<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

/**
 * Immutable, data-only masking configuration.
 *
 * Describes which header names, query-arg names and body keys must be redacted
 * in logs. All names are matched case-insensitively by the MessageMasker; this
 * value object holds the lists only and performs no masking itself.
 */
final class MaskingConfig
{
    /**
     * @param  list<string>  $headerNames  header names to mask
     * @param  list<string>  $queryNames  query-arg names to mask
     * @param  list<string>  $bodyKeys  body keys to mask
     */
    private function __construct(
        public readonly array $headerNames,
        public readonly array $queryNames,
        public readonly array $bodyKeys,
    ) {
    }

    /**
     * @param  list<string>  $headerNames
     * @param  list<string>  $queryNames
     * @param  list<string>  $bodyKeys
     */
    public static function create(
        array $headerNames = [],
        array $queryNames = [],
        array $bodyKeys = [],
    ): self {
        return new self($headerNames, $queryNames, $bodyKeys);
    }
}
