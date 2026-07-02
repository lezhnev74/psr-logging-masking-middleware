<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware;

/**
 * Matches a configured body key against a location in a decoded JSON body.
 *
 * A location is a path: the list of keys from the JSON root down to the current
 * node (numeric array indices stringified). A configured key is either:
 *
 *  - flat (no "."): matches when it equals the current (last) path element, at
 *    any depth - this reproduces the masker's original key-name behavior;
 *  - a dot-path (e.g. "users.*.password"): split on ".", it matches the full
 *    path from the root, where "*" consumes exactly one element and "**" any
 *    number (zero or more).
 *
 * Matching is case-insensitive per segment. The engine is pure and stateless.
 */
final class KeyPathMatcher
{
    /**
     * True when any configured key matches the given root-to-node path.
     *
     * @param  list<string>  $keys  configured body keys (flat or dot-path)
     * @param  list<string>  $path  keys from the JSON root to the current node
     */
    public function matches(array $keys, array $path): bool
    {
        foreach ($keys as $key) {
            if ($this->matchesKey($key, $path)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $path
     */
    private function matchesKey(string $key, array $path): bool
    {
        if ($path === []) {
            return false;
        }

        // Flat key: compare against the current (last) element only, any depth.
        if (! str_contains($key, '.')) {
            return strcasecmp($key, $path[count($path) - 1]) === 0;
        }

        return $this->matchesSegments(explode('.', $key), $path, 0, 0);
    }

    /**
     * Walks segments against the path with "*"/"**" wildcards, backtracking on
     * "**" (which consumes zero or more elements).
     *
     * @param  list<string>  $segments
     * @param  list<string>  $path
     */
    private function matchesSegments(array $segments, array $path, int $s, int $p): bool
    {
        if ($s === count($segments)) {
            return $p === count($path);
        }

        if ($segments[$s] === '**') {
            return $this->matchesDeep($segments, $path, $s, $p);
        }

        return $p < count($path)
            && $this->segmentEquals($segments[$s], $path[$p])
            && $this->matchesSegments($segments, $path, $s + 1, $p + 1);
    }

    /**
     * Handles a "**" segment: match zero elements here, or consume one and stay.
     *
     * @param  list<string>  $segments
     * @param  list<string>  $path
     */
    private function matchesDeep(array $segments, array $path, int $s, int $p): bool
    {
        return $this->matchesSegments($segments, $path, $s + 1, $p)
            || ($p < count($path) && $this->matchesSegments($segments, $path, $s, $p + 1));
    }

    /**
     * A single (non-"**") segment matches one path element: "*" matches any,
     * otherwise a case-insensitive literal comparison.
     */
    private function segmentEquals(string $segment, string $element): bool
    {
        return $segment === '*' || strcasecmp($segment, $element) === 0;
    }
}
