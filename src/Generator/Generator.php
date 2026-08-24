<?php

declare(strict_types=1);

namespace Zerotoprod\Sdk\Generator;

/**
 * The pipeline, front to back: load the document, name everything, map schemas
 * to models, map paths to routes, emit, format, report.
 *
 * Both mappers always run even when only one half is being written, because
 * routes name the classes that request and response bodies promote to — mapping
 * one without the other would hand out different names.
 *
 * @internal
 */
final class Generator
{
    public static function run(GeneratorConfig $config): GeneratorResult
    {
        $document = Document::load($config->source, $config->reader);
        $naming = new Naming();

        // Before anything is named: the hand-written models the sweep keeps own
        // their class names, so a document schema cannot overwrite one of them.
        $naming->reserveClasses($config->retainModels);

        $schemas = new SchemaMapper($document, $naming, $config->docsUrl);

        // Named schemas first: they claim their canonical class names, and
        // inline promotions take any discriminator that follows.
        $schemas->mapComponentSchemas();

        $routes = new RouteMapper($document, $naming, $schemas);
        $plan = $routes->map($config->webhooks);

        // After the routes, never before: a `listOf:` element class is only a
        // reachable body once the route mapper has settled on it.
        $pruned = $config->prune ? $schemas->prune() : 0;

        $emitter = new Emitter($config, $config->formatter);
        $files = [];
        $deleted = [];

        if ($config->models) {
            // Sweep before emitting, never after: the models about to be written
            // are simply rewritten, and anything the document no longer declares
            // is gone rather than left behind as an orphan.
            $deleted = $emitter->sweep($config->retainModels);
            $files = $emitter->emitModels($schemas->components());
        }

        if ($config->routes) {
            $files[] = $emitter->emitRoutes($plan);
        }

        return new GeneratorResult(
            $schemas->modelCount(),
            $schemas->enumCount(),
            count($plan->cases),
            $plan->operationCount(),
            $config->dryRun ? [] : $files,
            [...$schemas->skips(), ...$routes->skips()],
            $config->dryRun,
            $emitter->format($files),
            $schemas->reusedEnumCount(),
            $pruned,
            count($deleted),
        );
    }
}
