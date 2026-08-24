<?php

namespace Unit\Generator;

use PHPUnit\Framework\Attributes\Test;
use ReflectionEnum;
use Symfony\Component\Yaml\Yaml;
use Tests\Unit\Generator\GeneratorCase;
use Zerotoprod\Sdk\Generator\Generator;
use Zerotoprod\Sdk\Generator\GeneratorConfig;
use Zerotoprod\Sdk\Generator\GeneratorException;
use Zerotoprod\Sdk\Internal\AdminApi;
use Zerotoprod\Sdk\Internal\HasRoute;
use Zerotoprod\Sdk\Internal\HttpMethod;

class GeneratorTest extends GeneratorCase
{
    #[Test]
    public function it_reports_what_it_generated(): void
    {
        $result = $this->generate('widgets');

        self::assertSame(5, $result->models);
        self::assertSame(1, $result->enums);
        self::assertSame(2, $result->routes);
        self::assertSame(5, $result->operations);
        self::assertFalse($result->dryRun);
        self::assertFalse($result->formatted);
        self::assertCount(7, $result->files);
    }

    #[Test]
    public function every_generated_file_is_valid_php(): void
    {
        $result = $this->generate('widgets');

        foreach ($result->files as $file) {
            self::assertLints($file);
        }
    }

    #[Test]
    public function it_writes_the_expected_class_list(): void
    {
        $this->generate('widgets');

        self::assertSame(
            ['CreateWidgetRequest', 'ListWidgetsResponse', 'Pagination', 'Widget', 'WidgetOwner', 'WidgetStatus'],
            $this->models(),
        );
    }

    #[Test]
    public function generating_twice_produces_identical_bytes(): void
    {
        $first = $this->generate('widgets');
        $before = array_map(static fn(string $f): string => (string) file_get_contents($f), $first->files);

        $second = $this->generate('widgets');
        $after = array_map(static fn(string $f): string => (string) file_get_contents($f), $second->files);

        self::assertSame($first->files, $second->files);
        self::assertSame($before, $after);
    }

    #[Test]
    public function a_yaml_document_generates_byte_identical_output_to_its_json_twin(): void
    {
        // The YAML twin is dumped from the JSON fixture rather than committed
        // alongside it: one fixture stays the single source of truth, and the
        // test still proves the format never reaches past Document::parse().
        $json = $this->generate('widgets');
        $before = array_map(static fn(string $f): string => (string) file_get_contents($f), $json->files);

        $yaml = $this->temp() . '/widgets.yaml';
        file_put_contents($yaml, Yaml::dump(
            json_decode((string) file_get_contents(self::fixture('widgets.json')), true),
            32,
        ));

        $result = Generator::run(new GeneratorConfig(source: $yaml, root: $this->temp()));

        self::assertSame($json->files, $result->files);
        self::assertSame(
            $before,
            array_map(static fn(string $f): string => (string) file_get_contents($f), $result->files),
        );
    }

    #[Test]
    public function a_dry_run_writes_nothing_and_reports_no_files(): void
    {
        $result = Generator::run(new GeneratorConfig(
            source: self::fixture('widgets.json'),
            root: $this->temp(),
            dryRun: true,
        ));

        self::assertTrue($result->dryRun);
        self::assertSame([], $result->files);
        self::assertSame(5, $result->models);
        self::assertDirectoryDoesNotExist($this->temp() . '/src');
    }

    #[Test]
    public function models_only_leaves_the_route_enum_alone(): void
    {
        $this->generate('widgets', routes: false);

        self::assertDirectoryExists($this->temp() . '/src/Models');
        self::assertFileDoesNotExist($this->temp() . '/src/ApiRoute.php');
    }

    #[Test]
    public function routes_only_leaves_the_models_alone(): void
    {
        $this->generate('widgets', models: false);

        self::assertDirectoryDoesNotExist($this->temp() . '/src/Models');
        self::assertFileExists($this->temp() . '/src/ApiRoute.php');
    }

    #[Test]
    public function routes_only_still_names_the_body_classes_it_references(): void
    {
        // The route plan needs the schema mapper to have run, or request and
        // response classes would come out under different names.
        $this->generate('widgets', models: false);

        self::assertStringContainsString(
            'request: CreateWidgetRequest::class',
            (string) file_get_contents($this->temp() . '/src/ApiRoute.php'),
        );
    }

    #[Test]
    public function a_reader_closure_is_used_instead_of_the_filesystem(): void
    {
        $result = Generator::run(new GeneratorConfig(
            source: 'https://example.test/openapi.json',
            root: $this->temp(),
            dryRun: true,
            reader: static fn(): string => (string) file_get_contents(self::fixture('widgets.json')),
        ));

        self::assertSame(5, $result->models);
    }

    #[Test]
    public function the_formatter_is_invoked_with_everything_written(): void
    {
        $seen = [];
        $result = Generator::run(new GeneratorConfig(
            source: self::fixture('widgets.json'),
            root: $this->temp(),
            formatter: function (array $files) use (&$seen): void {
                $seen = $files;
            },
        ));

        self::assertTrue($result->formatted);
        self::assertSame($result->files, $seen);
    }

    #[Test]
    public function a_load_failure_surfaces_as_a_generator_exception(): void
    {
        $this->expectException(GeneratorException::class);

        Generator::run(new GeneratorConfig(source: '/no/such/spec.json', root: $this->temp()));
    }

    // ─── Sweeping what the document no longer declares ─────────────────

    #[Test]
    public function a_run_sweeps_models_the_document_no_longer_declares(): void
    {
        mkdir($this->temp() . '/src/Models', 0o775, true);
        mkdir($this->temp() . '/factories', 0o775, true);
        touch($this->temp() . '/src/Models/Stale.php');
        touch($this->temp() . '/src/Models/Errors.php');
        touch($this->temp() . '/factories/StaleFactory.php');
        touch($this->temp() . '/factories/ErrorsFactory.php');

        $result = Generator::run(new GeneratorConfig(
            source: self::fixture('widgets.json'),
            root: $this->temp(),
            retainModels: ['Errors'],
        ));

        self::assertSame(2, $result->deleted);
        self::assertNotContains('Stale', $this->models());
        self::assertContains('Errors', $this->models());
        self::assertFileDoesNotExist($this->temp() . '/factories/StaleFactory.php');
        self::assertFileExists($this->temp() . '/factories/ErrorsFactory.php');
    }

    #[Test]
    public function routes_only_sweeps_nothing(): void
    {
        mkdir($this->temp() . '/src/Models', 0o775, true);
        touch($this->temp() . '/src/Models/Stale.php');

        $result = $this->generate('widgets', models: false);

        self::assertSame(0, $result->deleted);
        self::assertFileExists($this->temp() . '/src/Models/Stale.php');
    }

    #[Test]
    public function a_dry_run_plans_the_sweep_without_deleting(): void
    {
        mkdir($this->temp() . '/src/Models', 0o775, true);
        touch($this->temp() . '/src/Models/Stale.php');

        $result = Generator::run(new GeneratorConfig(
            source: self::fixture('widgets.json'),
            root: $this->temp(),
            dryRun: true,
        ));

        self::assertSame(1, $result->deleted);
        self::assertFileExists($this->temp() . '/src/Models/Stale.php');
    }

    // ─── Reachability pruning ──────────────────────────────────────────

    #[Test]
    public function only_schemas_an_api_method_can_reach_are_emitted(): void
    {
        $result = $this->generate('reachability');

        self::assertSame(['Tag', 'TagCommit', 'WebhookConfig', 'WebhookConfigInsecureSsl'], $this->models());
        self::assertSame(3, $result->pruned);
    }

    #[Test]
    public function a_path_reachable_schema_is_never_pruned_for_looking_webhook_ish(): void
    {
        // `webhook-config` is what three GitHub path operations return. Pruning
        // by name prefix would delete a class the SDK needs.
        $this->generate('reachability');

        self::assertContains('WebhookConfig', $this->models());
        self::assertNotContains('PushEvent', $this->models());
    }

    #[Test]
    public function a_schema_reachable_only_as_a_list_element_survives(): void
    {
        // `tag` is named nowhere but in a bare-array response's `items`, and
        // `tag-commit` only inside `tag`.
        $this->generate('reachability');

        self::assertContains('Tag', $this->models());
        self::assertContains('TagCommit', $this->models());
    }

    #[Test]
    public function a_webhook_only_schema_is_emitted_once_webhooks_are_on(): void
    {
        $result = $this->generate('reachability', webhooks: true);

        self::assertContains('PushEvent', $this->models());
        self::assertContains('Pusher', $this->models());
        self::assertSame(1, $result->pruned);
    }

    #[Test]
    public function a_schema_reachable_from_nothing_is_pruned_and_counted(): void
    {
        $result = $this->generate('reachability', webhooks: true);

        self::assertNotContains('Orphan', $this->models());
        self::assertStringContainsString(
            'pruned      1 (unreachable from paths; pass --webhooks to include)',
            $result->summary(),
        );
    }

    #[Test]
    public function pruning_off_keeps_every_schema_the_document_declares(): void
    {
        $result = $this->generate('reachability', prune: false);

        self::assertContains('Orphan', $this->models());
        self::assertContains('PushEvent', $this->models());
        self::assertSame(0, $result->pruned);
        self::assertStringNotContainsString('pruned', $result->summary());
    }

    // ─── The emitted enum, compiled and reflected ──────────────────────

    #[Test]
    public function the_emitted_route_enum_compiles_and_its_attributes_reflect(): void
    {
        $enum = new ReflectionEnum($this->loadRouteEnum('widgets'));

        self::assertSame(['widgets', 'widget'], array_map(
            static fn(\ReflectionEnumBackedCase $case): string => $case->getName(),
            $enum->getCases(),
        ));

        $widgets = $enum->getCase('widgets');
        self::assertSame('/v1/widgets', $widgets->getBackingValue());
        self::assertCount(1, $widgets->getAttributes(HasRoute::class));

        $operations = array_map(
            static fn(\ReflectionAttribute $attribute): AdminApi => $attribute->newInstance(),
            $widgets->getAttributes(AdminApi::class),
        );

        self::assertCount(2, $operations);

        [$list, $create] = $operations;

        self::assertSame(HttpMethod::GET, $list->method);
        self::assertSame('listWidgets', $list->name);
        self::assertSame([], $list->pathParams);
        self::assertSame(['per_page', 'page'], $list->queryParams);
        self::assertNull($list->request);
        self::assertSame('ListWidgetsResponse', AdminApi::shortName($list->response));

        self::assertSame(HttpMethod::POST, $create->method);
        self::assertSame('createWidget', $create->name);
        self::assertSame('CreateWidgetRequest', AdminApi::shortName($create->request));
        self::assertSame('Widget', AdminApi::shortName($create->response));
    }

    #[Test]
    public function a_single_item_route_carries_its_path_parameters(): void
    {
        $enum = new ReflectionEnum($this->loadRouteEnum('widgets'));
        $case = $enum->getCase('widget');

        $operations = array_map(
            static fn(\ReflectionAttribute $attribute): AdminApi => $attribute->newInstance(),
            $case->getAttributes(AdminApi::class),
        );

        self::assertSame(
            ['getWidget', 'updateWidget', 'deleteWidget'],
            array_map(static fn(AdminApi $api): string => $api->name, $operations),
        );

        foreach ($operations as $operation) {
            self::assertSame(['id'], $operation->pathParams);
        }

        self::assertNull($operations[2]->response, 'a 204 delete has no response model');
    }

    #[Test]
    public function put_and_patch_on_one_path_reflect_as_update_and_patch(): void
    {
        $enum = new ReflectionEnum($this->loadRouteEnum('methods'));

        $operations = array_map(
            static fn(\ReflectionAttribute $attribute): AdminApi => $attribute->newInstance(),
            $enum->getCase('both')->getAttributes(AdminApi::class),
        );

        self::assertSame(
            [[HttpMethod::PUT, 'updateBoth'], [HttpMethod::PATCH, 'patchBoth']],
            array_map(static fn(AdminApi $api): array => [$api->method, $api->name], $operations),
        );
    }

    // ─── The emitted models, loaded and hydrated ───────────────────────

    #[Test]
    public function the_emitted_models_hydrate_a_payload(): void
    {
        $namespace = $this->loadModels('widgets');

        /** @var class-string $widget */
        $widget = "$namespace\\Widget";
        $model = $widget::from([
            'id' => 'w_1',
            'name' => 'Sprocket',
            'status' => 'archived',
            'tags' => ['a', 'b'],
            'owner' => ['login' => 'dev'],
            'meta' => ['k' => 'v'],
            '2fa' => true,
        ]);

        self::assertSame('w_1', $model->id);
        self::assertSame('Sprocket', $model->name);
        self::assertSame('archived', $model->status->value);
        self::assertSame(['a', 'b'], $model->tags);
        self::assertSame('dev', $model->owner->login);
        self::assertSame(['k' => 'v'], $model->meta);
        self::assertTrue($model->_2fa);
    }

    #[Test]
    public function an_absent_required_enum_falls_back_to_unknown(): void
    {
        $namespace = $this->loadModels('widgets');

        /** @var class-string $widget */
        $widget = "$namespace\\Widget";
        $model = $widget::from(['id' => 'w_2']);

        self::assertSame('unknown', $model->status->value);
        self::assertNull($model->name);
        self::assertSame([], $model->tags);
    }

    #[Test]
    public function a_list_of_models_hydrates_through_the_map_of_cast(): void
    {
        $namespace = $this->loadModels('widgets');

        /** @var class-string $response */
        $response = "$namespace\\ListWidgetsResponse";
        $model = $response::from([
            'widgets' => [['id' => 'a'], ['id' => 'b']],
            'Pagination' => ['current_page' => 2],
        ]);

        self::assertCount(2, $model->widgets);
        self::assertSame('a', $model->widgets[0]->id);
        self::assertSame(2, $model->Pagination->current_page);
    }

    /**
     * Generate a fixture, then load the route enum under a namespace of its own
     * so it cannot collide with the package's real `ApiRoute`. Only the
     * namespace line changes: the `Internal\*` imports still point at the real
     * attribute classes, and `Model::class` in an attribute argument is a
     * compile-time string that never needs the class to exist.
     *
     * @return class-string
     */
    private function loadRouteEnum(string $fixture): string
    {
        $this->generate($fixture, models: false);

        $namespace = 'Tests\\Generated\\R' . bin2hex(random_bytes(6));
        $path = $this->temp() . '/ApiRouteUnderTest.php';

        file_put_contents($path, str_replace(
            'namespace Zerotoprod\\Sdk;',
            "namespace $namespace;",
            (string) file_get_contents($this->temp() . '/src/ApiRoute.php'),
        ));

        require $path;

        /** @var class-string $class */
        $class = "$namespace\\ApiRoute";

        return $class;
    }

    /**
     * Generate a fixture's models and load them all under a namespace of their
     * own. Rewriting the namespace uniformly keeps their references to each
     * other intact, while `Internal\DataModel` and `Describe` stay real.
     */
    private function loadModels(string $fixture): string
    {
        $this->generate($fixture, routes: false);

        $namespace = 'Tests\\Generated\\M' . bin2hex(random_bytes(6));

        foreach (glob($this->temp() . '/src/Models/*.php') ?: [] as $file) {
            $loadable = dirname($file) . '/loaded-' . basename($file);
            file_put_contents($loadable, str_replace(
                'namespace Zerotoprod\\Sdk\\Models;',
                "namespace $namespace;",
                (string) file_get_contents($file),
            ));

            require $loadable;
        }

        return $namespace;
    }
}
