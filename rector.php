<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\DeadCode\Rector\ClassMethod\RemoveUnusedPublicMethodParameterRector;
use Rector\DeadCode\Rector\Concat\RemoveConcatAutocastRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // Target the minimum supported version so no 8.2+ syntax is introduced.
    ->withPhpSets(php81: true)
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true
    )
    // Keep the deliberate "(string) $message->getBody()" copy: reading the body
    // through an explicit string cast is a hard invariant (never consume the real
    // stream), so this autocast-removal rule must not touch the serializer.
    ->withSkip([
        RemoveConcatAutocastRector::class,
        // The promise thenable stubs deliberately mirror the two-argument
        // then($onFulfilled, $onRejected) contract the middleware taps, so the
        // "unused" $onRejected parameter must stay - it is the contract, not dead code.
        RemoveUnusedPublicMethodParameterRector::class => [
            __DIR__.'/tests/HandlerMiddlewareTest.php',
        ],
    ]);
