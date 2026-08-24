<?php

declare(strict_types=1);

namespace Zerotoprod\Sdk\Generator;

use Closure;

/**
 * Refuses to overwrite hand edits.
 *
 * A run rewrites all of `src/Models/` and `src/ApiRoute.php`. If someone has
 * tweaked a generated model and not committed it, regenerating would destroy the
 * change with nothing to recover from — so the run stops unless `--force` says
 * otherwise.
 *
 * @internal
 */
final class Guard
{
    /**
     * Paths with uncommitted changes among those about to be overwritten.
     *
     * `$runner` receives a shell command and returns its stdout, so this is
     * testable without a repository.
     *
     * @param  list<string>            $paths
     * @param  Closure(string): string $runner
     * @return list<string>
     */
    public static function dirty(string $root, array $paths, Closure $runner): array
    {
        if ($paths === []) {
            return [];
        }

        $command = 'git -C ' . escapeshellarg($root) . ' status --porcelain -- '
            . implode(' ', array_map(escapeshellarg(...), $paths));

        $dirty = [];

        foreach (explode("\n", $runner($command)) as $line) {
            // Porcelain v1: two status columns, a space, then the path.
            $path = trim(substr($line, 3));

            if ($path !== '') {
                $dirty[] = $path;
            }
        }

        sort($dirty);

        return $dirty;
    }

    /**
     * Hand-written models named in `retain_models` that have no file on disk.
     *
     * @param  list<string> $retain Short class names.
     * @return list<string> Paths relative to the root, sorted.
     */
    public static function absent(string $root, array $retain): array
    {
        $missing = [];

        foreach ($retain as $class) {
            if (!is_file("$root/src/Models/$class.php")) {
                $missing[] = "src/Models/$class.php";
            }
        }

        sort($missing);

        return $missing;
    }

    /**
     * Stop a run that would produce a package referencing classes it does not
     * have.
     *
     * The models in `retain_models` are hand-written: no document declares
     * them, so a run cannot recreate one that has been deleted -- and the
     * shared client code in `src/` resolves `Models\Errors` and
     * `Models\Query` directly. Left absent, the run finishes reporting
     * success and static analysis is the first thing to notice, as a wall of
     * `class.notFound` in files nobody touched. Failing here instead names the
     * files and how to get them back.
     *
     * @param  list<string> $retain Short class names.
     * @throws GeneratorException when any of them is missing.
     */
    public static function assertRetained(string $root, array $retain): void
    {
        $missing = self::absent($root, $retain);

        if ($missing === []) {
            return;
        }

        throw new GeneratorException(
            "Missing hand-written models named in `retain_models`:\n"
            . implode("\n", array_map(static fn(string $path): string => "  $path", $missing))
            . "\n\nNo document declares these, so generation cannot recreate them, and"
            . "\nshared code in src/ resolves them. Restore them:\n\n  git restore --staged --worktree "
            . implode(' ', $missing)
            . "\n\nor drop the name from `retain_models` in sdk.json if this package"
            . "\nno longer has that model.",
        );
    }

    /**
     * @param  list<string> $paths
     * @throws GeneratorException when anything is dirty and `$force` is false.
     */
    public static function assertClean(string $root, array $paths, Closure $runner, bool $force): void
    {
        if ($force) {
            return;
        }

        $dirty = self::dirty($root, $paths, $runner);

        if ($dirty === []) {
            return;
        }

        throw new GeneratorException(
            "Refusing to overwrite uncommitted changes:\n"
            . implode("\n", array_map(static fn(string $path): string => "  $path", $dirty))
            . "\n\nCommit or stash them first, or pass --force to overwrite.",
        );
    }
}
