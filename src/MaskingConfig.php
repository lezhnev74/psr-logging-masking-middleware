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

    /**
     * Merge this config with any number of others into a single config.
     *
     * Each list is the concatenation of the receiver's and every other's list,
     * deduplicated case-insensitively with first-seen order and casing kept.
     * The receiver is never mutated; a fresh clone is returned.
     */
    public function merge(self ...$others): self
    {
        $headerNames = $this->headerNames;
        $queryNames = $this->queryNames;
        $bodyKeys = $this->bodyKeys;

        foreach ($others as $other) {
            $headerNames = array_merge($headerNames, $other->headerNames);
            $queryNames = array_merge($queryNames, $other->queryNames);
            $bodyKeys = array_merge($bodyKeys, $other->bodyKeys);
        }

        return self::create(
            $this->dedupeInsensitive($headerNames),
            $this->dedupeInsensitive($queryNames),
            $this->dedupeInsensitive($bodyKeys),
        );
    }

    /**
     * @param  list<string>  $names
     * @return list<string>
     */
    private function dedupeInsensitive(array $names): array
    {
        $result = [];
        $seen = [];
        foreach ($names as $name) {
            $key = strtolower($name);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $result[] = $name;
        }

        return $result;
    }
}
