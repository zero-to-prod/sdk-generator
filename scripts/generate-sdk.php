<?php

declare(strict_types=1);

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/manifest.php';

use Zerotoprod\Sdk\Generator\CliOptions;
use Zerotoprod\Sdk\Generator\Emitter;
use Zerotoprod\Sdk\Generator\Generator;
use Zerotoprod\Sdk\Generator\GeneratorConfig;
use Zerotoprod\Sdk\Generator\GeneratorException;
use Zerotoprod\Sdk\Generator\Guard;

$root = dirname(__DIR__);

try {
    /** @var list<string> $arguments */
    $arguments = array_slice($argv, 1);
    $options = CliOptions::parse($arguments);

    $openapi = manifest()['openapi'] ?? null;
    $source = $options->source
        ?? (is_array($openapi) && is_string($openapi['source'] ?? null) ? $openapi['source'] : null);

    if ($source === null || trim($source) === '') {
        throw new GeneratorException(
            "No OpenAPI document given.\nPass a path or URL, or set `openapi.source` in sdk.json.\n"
            . CliOptions::USAGE
        );
    }

    $out = $options->out ?? $root;

    $config = new GeneratorConfig(
        source: $source,
        root: $out,
        namespace: manifestString('namespace'),
        docsUrl: manifestString('docs_url'),
        models: $options->writesModels(),
        routes: $options->writesRoutes(),
        webhooks: $options->webhooks,
        prune: !$options->allSchemas,
        dryRun: $options->dryRun,
        formatter: Emitter::phpCsFixer($root),
        // Hand-written models the sweep must leave alone. An older sdk.json with
        // no `retain_models` key falls back to the template's three rather than
        // reading as "retain nothing" and deleting them.
        retainModels: manifestList('retain_models', ['Errors', 'Pagination', 'Query']),
    );

    // Nothing the document can fix: the hand-written models named in
    // `retain_models` are what the shared client code in src/ resolves, so a run
    // that leaves one absent produces a package static analysis reads as broken.
    // Checked on a dry run too -- that is the run you make to find out.
    if ($out === $root) {
        Guard::assertRetained($root, $config->retainModels);
    }

    // Never clobber a hand edit that is not committed anywhere.
    if (!$options->dryRun && $out === $root) {
        Guard::assertClean(
            $root,
            $config->overwrites(),
            static fn (string $command): string => (string) shell_exec($command),
            $options->force
        );
    }

    echo "Reading $source\n";

    $result = Generator::run($config);

    echo $result->summary(), "\n";

    if ($options->verbose && $result->skips !== []) {
        echo "\nSkipped:\n", $result->skipReport(), "\n";
    }

    // The `@method` block on the API class is derived from the generated
    // ApiRoute, so it belongs to the generated surface rather than to the
    // formatting pass in `composer fix`. Regenerating it here is what keeps
    // `src/` self-consistent the moment a run finishes: left stale it would
    // still name the models this run has just swept, which static analysis
    // reads — correctly — as missing classes.
    if (!$options->dryRun && $options->writesRoutes() && $out === $root) {
        echo "\n";

        $status = 0;
        passthru(
            escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/generate-api-methods.php'),
            $status
        );

        exit($status);
    }

    exit(0);
} catch (GeneratorException $exception) {
    fwrite(STDERR, $exception->getMessage() . "\n");
    exit(1);
}
