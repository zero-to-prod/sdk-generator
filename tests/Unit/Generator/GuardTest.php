<?php

namespace Unit\Generator;

use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Generator\GeneratorCase;
use Zerotoprod\Sdk\Generator\GeneratorException;
use Zerotoprod\Sdk\Generator\Guard;

class GuardTest extends GeneratorCase
{
    #[Test]
    public function no_paths_means_nothing_to_check(): void
    {
        $called = false;
        $runner = function () use (&$called): string {
            $called = true;

            return '';
        };

        self::assertSame([], Guard::dirty('/repo', [], $runner));
        self::assertFalse($called);
    }

    #[Test]
    public function it_parses_porcelain_output_into_paths(): void
    {
        $runner = static fn(): string => " M src/Models/Widget.php\n?? src/Models/New.php\n";

        self::assertSame(
            ['src/Models/New.php', 'src/Models/Widget.php'],
            Guard::dirty('/repo', ['src/Models'], $runner),
        );
    }

    #[Test]
    public function it_scopes_the_command_to_the_repo_and_paths(): void
    {
        $seen = '';
        $runner = function (string $command) use (&$seen): string {
            $seen = $command;

            return '';
        };

        Guard::dirty('/repo dir', ['src/Models', 'src/ApiRoute.php'], $runner);

        self::assertStringContainsString("git -C '/repo dir' status --porcelain --", $seen);
        self::assertStringContainsString("'src/Models' 'src/ApiRoute.php'", $seen);
    }

    #[Test]
    public function a_clean_tree_passes_the_assertion(): void
    {
        Guard::assertClean('/repo', ['src/Models'], static fn(): string => '', false);

        self::expectNotToPerformAssertions();
    }

    #[Test]
    public function a_dirty_tree_is_refused(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Refusing to overwrite uncommitted changes');

        Guard::assertClean('/repo', ['src/Models'], static fn(): string => ' M src/Models/Widget.php', false);
    }

    #[Test]
    public function the_refusal_names_the_files_and_suggests_force(): void
    {
        try {
            Guard::assertClean('/repo', ['src/Models'], static fn(): string => ' M src/Models/Widget.php', false);
            self::fail('expected a refusal');
        } catch (GeneratorException $exception) {
            self::assertStringContainsString('src/Models/Widget.php', $exception->getMessage());
            self::assertStringContainsString('--force', $exception->getMessage());
        }
    }

    #[Test]
    public function force_skips_the_check_entirely(): void
    {
        $called = false;
        $runner = function () use (&$called): string {
            $called = true;

            return ' M src/Models/Widget.php';
        };

        Guard::assertClean('/repo', ['src/Models'], $runner, true);

        self::assertFalse($called);
    }

    #[Test]
    public function a_retained_model_that_exists_is_not_reported(): void
    {
        $root = $this->temp();
        mkdir("$root/src/Models", 0o775, true);
        file_put_contents("$root/src/Models/Errors.php", '<?php');

        self::assertSame([], Guard::absent($root, ['Errors']));

        Guard::assertRetained($root, ['Errors']);
    }

    #[Test]
    public function a_missing_retained_model_is_reported_by_path(): void
    {
        $root = $this->temp();
        mkdir("$root/src/Models", 0o775, true);
        file_put_contents("$root/src/Models/Pagination.php", '<?php');

        self::assertSame(
            ['src/Models/Errors.php', 'src/Models/Query.php'],
            Guard::absent($root, ['Errors', 'Pagination', 'Query']),
        );
    }

    #[Test]
    public function nothing_retained_means_nothing_to_check(): void
    {
        self::assertSame([], Guard::absent('/nowhere', []));
    }

    #[Test]
    public function a_missing_retained_model_stops_the_run_and_says_how_to_restore_it(): void
    {
        try {
            Guard::assertRetained($this->temp(), ['Errors', 'Query']);
            self::fail('expected a refusal');
        } catch (GeneratorException $exception) {
            self::assertStringContainsString('src/Models/Errors.php', $exception->getMessage());
            self::assertStringContainsString('src/Models/Query.php', $exception->getMessage());
            self::assertStringContainsString('git restore', $exception->getMessage());
            self::assertStringContainsString('retain_models', $exception->getMessage());
        }
    }
}
