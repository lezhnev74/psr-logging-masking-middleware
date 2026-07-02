<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    // Target the minimum supported version so no 8.2+ syntax is introduced.
    ->withPhpSets(php81: true)
    ->withPreparedSets(codeQuality: true);
