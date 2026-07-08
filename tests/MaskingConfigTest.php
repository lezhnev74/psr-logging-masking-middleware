<?php

declare(strict_types=1);

namespace Lezhnev74\PsrLoggingMaskingMiddleware\Tests;

use Lezhnev74\PsrLoggingMaskingMiddleware\MaskingConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * MaskingConfig is impl-agnostic data, so it uses the plain TestCase - the
 * cross-impl PsrImplTestCase base is reserved for masking/serialization pieces.
 */
final class MaskingConfigTest extends TestCase
{
    public function testCreateStoresTheThreeLists(): void
    {
        $config = MaskingConfig::create(
            headerNames: ['Authorization', 'Cookie'],
            queryNames: ['token', 'api_key'],
            bodyKeys: ['password', 'secret'],
        );

        self::assertSame(['Authorization', 'Cookie'], $config->headerNames);
        self::assertSame(['token', 'api_key'], $config->queryNames);
        self::assertSame(['password', 'secret'], $config->bodyKeys);
    }

    public function testCreateDefaultsToEmptyLists(): void
    {
        $config = MaskingConfig::create();

        self::assertSame([], $config->headerNames);
        self::assertSame([], $config->queryNames);
        self::assertSame([], $config->bodyKeys);
    }

    public function testPropertiesAreReadonly(): void
    {
        $reflection = new \ReflectionClass(MaskingConfig::class);

        foreach (['headerNames', 'queryNames', 'bodyKeys'] as $property) {
            self::assertTrue(
                $reflection->getProperty($property)->isReadOnly(),
                "Property {$property} must be readonly (immutable value object).",
            );
        }
    }

    /**
     * @param  array{list<string>, list<string>, list<string>}  $receiver
     * @param  list<array{list<string>, list<string>, list<string>}>  $others
     * @param  array{list<string>, list<string>, list<string>}  $expected
     */
    #[DataProvider('mergeCases')]
    public function testMerge(array $receiver, array $others, array $expected): void
    {
        $base = MaskingConfig::create(...$receiver);
        $otherConfigs = array_map(
            static fn (array $lists): MaskingConfig => MaskingConfig::create(...$lists),
            $others,
        );

        $merged = $base->merge(...$otherConfigs);

        self::assertSame($expected[0], $merged->headerNames);
        self::assertSame($expected[1], $merged->queryNames);
        self::assertSame($expected[2], $merged->bodyKeys);
    }

    /**
     * @return array<string, array{
     *     array{list<string>, list<string>, list<string>},
     *     list<array{list<string>, list<string>, list<string>}>,
     *     array{list<string>, list<string>, list<string>}
     * }>
     */
    public static function mergeCases(): array
    {
        return [
            // empty inputs
            'two empty configs stay empty' => [
                [[], [], []],
                [[[], [], []]],
                [[], [], []],
            ],
            'no arguments returns a copy of the receiver' => [
                [['Authorization'], ['token'], ['password']],
                [],
                [['Authorization'], ['token'], ['password']],
            ],

            // disjoint union
            'single disjoint other unions all three lists in order' => [
                [['Authorization'], ['token'], ['password']],
                [[['Cookie'], ['api_key'], ['secret']]],
                [['Authorization', 'Cookie'], ['token', 'api_key'], ['password', 'secret']],
            ],

            // dedup across configs
            'exact duplicate across configs collapses to one' => [
                [['Authorization'], ['token'], ['password']],
                [[['Authorization'], ['token'], ['password']]],
                [['Authorization'], ['token'], ['password']],
            ],
            'case-variant duplicate keeps first-seen casing' => [
                [['Authorization'], ['Token'], ['Password']],
                [[['authorization'], ['token'], ['password']]],
                [['Authorization'], ['Token'], ['Password']],
            ],

            // dedup within a single config's own list
            'duplicate within the receiver own list is deduped' => [
                [['Authorization', 'authorization'], [], []],
                [],
                [['Authorization'], [], []],
            ],

            // variadic: multiple others
            'three-way merge keeps first-seen order and casing across all' => [
                [['Authorization'], [], []],
                [[['authorization', 'Cookie'], [], []], [['COOKIE', 'X-Api'], [], []]],
                [['Authorization', 'Cookie', 'X-Api'], [], []],
            ],

            // per-list independence
            'a name in one list does not dedupe against another list' => [
                [['token'], ['token'], ['token']],
                [[['token'], ['token'], ['token']]],
                [['token'], ['token'], ['token']],
            ],
        ];
    }

    public function testMergeDoesNotMutateTheReceiver(): void
    {
        $base = MaskingConfig::create(headerNames: ['Authorization']);

        $merged = $base->merge(MaskingConfig::create(headerNames: ['Cookie']));

        self::assertNotSame($base, $merged);
        self::assertSame(['Authorization'], $base->headerNames);
        self::assertSame(['Authorization', 'Cookie'], $merged->headerNames);
    }
}
