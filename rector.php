<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Exception\Configuration\InvalidConfigurationException;

try {
    return RectorConfig::configure()
        ->withPaths([
            __DIR__.'/src',
        ])
        ->withParallel(timeoutSeconds: 6000, jobSize: 4)
        ->withPhpSets(php81: true)
        ->withPreparedSets(
            deadCode: true,
            codeQuality: true,
            typeDeclarations: true,
        );
} catch (InvalidConfigurationException $e) {
    echo $e->getMessage();
}
