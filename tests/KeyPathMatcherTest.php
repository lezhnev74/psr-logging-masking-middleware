<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\KeyPathMatcher;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Truth table for KeyPathMatcher: does any configured body key match the given
 * root-to-node path? Flat keys match the current key at any depth (preserving
 * the masker's original behavior); dot-path keys anchor at the JSON root, with
 * "*" consuming exactly one path element and "**" zero-or-more. Matching is
 * case-insensitive per segment. Running every case through one method keeps the
 * boundaries between flat, exact, single- and deep-wildcard matches explicit.
 */
final class KeyPathMatcherTest extends TestCase
{
    /**
     * @param  list<string>  $keys
     * @param  list<string>  $path
     */
    #[DataProvider('matchCases')]
    public function testMatches(array $keys, array $path, bool $expected): void
    {
        self::assertSame($expected, (new KeyPathMatcher())->matches($keys, $path));
    }

    /**
     * @return array<string, array{list<string>, list<string>, bool}>
     */
    public static function matchCases(): array
    {
        return [
            // Flat keys: match the current (last) key at any depth.
            'flat matches last element at depth' => [['secret'], ['a', 'b', 'secret'], true],
            'flat matches at root' => [['secret'], ['secret'], true],
            'flat does not match a mid-path element' => [['b'], ['a', 'b', 'secret'], false],
            'flat is case-insensitive' => [['SECRET'], ['a', 'secret'], true],
            'flat no match' => [['token'], ['a', 'secret'], false],

            // Exact dot-paths anchored at the root.
            'exact path matches' => [['a.b.c'], ['a', 'b', 'c'], true],
            'exact path too short' => [['a.b.c'], ['a', 'b'], false],
            'exact path too long' => [['a.b.c'], ['a', 'b', 'c', 'd'], false],
            'exact path wrong middle' => [['a.b.c'], ['a', 'x', 'c'], false],
            'exact path not at root' => [['a.b'], ['x', 'a', 'b'], false],
            'exact path case-insensitive per segment' => [['A.b.C'], ['a', 'b', 'c'], true],

            // Single wildcard "*": exactly one element.
            'star matches one element' => [['users.*.password'], ['users', '0', 'password'], true],
            'star matches string key' => [['users.*.password'], ['users', 'admin', 'password'], true],
            'star needs exactly one' => [['users.*.password'], ['users', 'password'], false],
            'star does not span two' => [['users.*.password'], ['users', '0', 'x', 'password'], false],
            'leading star' => [['*.token'], ['a', 'token'], true],
            'leading star needs a level' => [['*.token'], ['token'], false],

            // Deep wildcard "**": zero-or-more elements.
            'double star zero elements' => [['a.**.secret'], ['a', 'secret'], true],
            'double star one element' => [['a.**.secret'], ['a', 'b', 'secret'], true],
            'double star many elements' => [['a.**.secret'], ['a', 'b', 'c', 'secret'], true],
            'double star wrong tail' => [['a.**.secret'], ['a', 'b', 'token'], false],
            'leading double star any depth' => [['**.token'], ['a', 'b', 'token'], true],
            'leading double star at root' => [['**.token'], ['token'], true],
            'trailing double star matches suffix' => [['a.**'], ['a', 'b', 'c'], true],
            'trailing double star matches self' => [['a.**'], ['a'], true],

            // Multiple keys: any match wins.
            'any of several keys matches' => [['x.y', 'users.*.password'], ['users', '0', 'password'], true],

            // Edges.
            'empty keys never match' => [[], ['a', 'b'], false],
            'empty path never matches' => [['a'], [], false],
        ];
    }
}
