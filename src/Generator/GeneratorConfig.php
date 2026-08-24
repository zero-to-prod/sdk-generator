<?php

declare(strict_types=1);

namespace Zerotoprod\Sdk\Generator;

use Closure;

/**
 * Everything one generator run needs to know.
 *
 * The two closures are seams, not features: `$reader` replaces the document
 * fetch and `$formatter` replaces the php-cs-fixer shell-out, so the pipeline
 * can be exercised without a network or a subprocess.
 *
 * @internal
 */
final class GeneratorConfig
{
    /**
     * @param string       $source    Path or URL of the OpenAPI document.
     * @param string       $root      Package root; output goes under `$root/src`.
     * @param string       $namespace Package namespace, e.g. `Zerotoprod\Sdk`.
     * @param string       $docsUrl   URL for every generated `@link`.
     * @param bool         $models    Write `src/Models/`.
     * @param bool         $routes    Write `src/ApiRoute.php`.
     * @param bool         $webhooks  Emit webhook payload models.
     * @param bool         $prune     Emit only the models an API method can reach; see SchemaMapper::prune().
     * @param bool         $dryRun    Plan only; write nothing.
     * @param Closure|null $reader    `fn(string $source): string|false`
     * @param Closure|null $formatter `fn(list<string> $files): void`
     * @param list<string> $retainModels Hand-written model classes the package owns, by short
     *                                   class name; comes from `retain_models` in sdk.json. The
     *                                   sweep never deletes them, the document may not claim
     *                                   their names, and a run refuses to start with one missing.
     */
    public function __construct(
        public readonly string $source,
        public readonly string $root,
        public readonly string $namespace = 'Zerotoprod\\Sdk',
        public readonly string $docsUrl = 'https://example.com/docs',
        public readonly bool $models = true,
        public readonly bool $routes = true,
        public readonly bool $webhooks = false,
        public readonly bool $prune = true,
        public readonly bool $dryRun = false,
        public readonly ?Closure $reader = null,
        public readonly ?Closure $formatter = null,
        public readonly array $retainModels = [],
    ) {}

    public function modelsDirectory(): string
    {
        return "$this->root/src/Models";
    }

    public function factoriesDirectory(): string
    {
        return "$this->root/factories";
    }

    public function apiRoutePath(): string
    {
        return "$this->root/src/ApiRoute.php";
    }

    public function modelNamespace(): string
    {
        return "$this->namespace\\Models";
    }

    /**
     * Paths the run overwrites, relative to the package root — the set the
     * uncommitted-changes guard checks.
     *
     * `factories` is in there whenever models are: the run deletes the factory
     * that belonged to every model it sweeps, so uncommitted work in there is
     * just as destroyable as uncommitted work under `src/Models`.
     *
     * @return list<string>
     */
    public function overwrites(): array
    {
        $paths = [];

        if ($this->models) {
            $paths[] = 'src/Models';
            $paths[] = 'factories';
        }

        if ($this->routes) {
            $paths[] = 'src/ApiRoute.php';
        }

        return $paths;
    }
}
