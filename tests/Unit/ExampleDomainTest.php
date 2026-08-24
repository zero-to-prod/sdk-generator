<?php

namespace Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Zerotoprod\Sdk\ApiResult;
use Zerotoprod\Sdk\ApiRoute;
use Zerotoprod\Sdk\Factories\CreateWidgetRequestFactory;
use Zerotoprod\Sdk\Factories\UpdateWidgetRequestFactory;
use Zerotoprod\Sdk\Factories\WidgetFactory;
use Zerotoprod\Sdk\Factories\WidgetsResponseFactory;
use Zerotoprod\Sdk\Factories\WidgetTagFactory;
use Zerotoprod\Sdk\Internal\HttpMethod;
use Zerotoprod\Sdk\Models\CreateWidgetRequest;
use Zerotoprod\Sdk\Models\Pagination;
use Zerotoprod\Sdk\Models\Query;
use Zerotoprod\Sdk\Models\UpdateWidgetRequest;
use Zerotoprod\Sdk\Models\Widget;
use Zerotoprod\Sdk\Models\WidgetsResponse;
use Zerotoprod\Sdk\Models\WidgetStatus;
use Zerotoprod\Sdk\Models\WidgetTag;
use Zerotoprod\Sdk\Options;
use Zerotoprod\Sdk\Response;
use Zerotoprod\Sdk\SdkApi;
use Zerotoprod\Sdk\SdkConfig;

/**
 * The shipped example domain — the `widget` / `widgets` / `widgetTags` cases in
 * `src/ApiRoute.php`, the `Widget*` models, and their factories.
 *
 * This is the ONLY test file that names any of them, and it is deliberately a
 * smoke test rather than a spec: everything it touches is example content that
 * `composer generate-sdk` overwrites or deletes on the first real generation, and
 * `php init` deletes this file. The dispatcher, `Route`, transports, hooks and
 * factory semantics are all specified in the other test files, against the
 * permanent fixtures in `tests/Fixtures/` — so deleting this one loses no
 * coverage of anything a derived package keeps.
 */
class ExampleDomainTest extends TestCase
{
    private const url = 'https://api.example.com';

    /**
     * @return array{SdkApi<Response>, \Zerotoprod\Sdk\Internal\Fake}
     */
    private function fake(): array
    {
        return SdkApi::fake([SdkConfig::url => self::url]);
    }

    // ──────────────────────────────────────────────────────────────
    // The routes the template ships in src/ApiRoute.php
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_example_routes_carry_the_paths_they_document(): void
    {
        self::assertSame('/v1/widgets/{id}', ApiRoute::widget->value);
        self::assertSame('/v1/widgets', ApiRoute::widgets->value);
        self::assertSame('/v1/widgets/{id}/tags', ApiRoute::widgetTags->value);
    }

    #[Test]
    public function the_generated_call_static_shim_renders_a_route(): void
    {
        self::assertSame('/v1/widgets?per_page=50', ApiRoute::widgets(['per_page' => 50])->render());
        self::assertSame('/v1/widgets', ApiRoute::widgets()->render());
        self::assertSame('/v1/widgets/{id}', ApiRoute::widget()->render());
    }

    #[Test]
    public function api_route_is_the_default_route_enum(): void
    {
        self::assertSame(ApiRoute::class, SdkConfig::from([])->route_enum);
    }

    // ──────────────────────────────────────────────────────────────
    // One dispatch per shipped operation
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function get_widget(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'data' => ['id' => '01H', 'name' => 'Example widget', 'status' => 'active'],
        ]) ?: ''));

        $result = $api->getWidget('01H');

        self::assertInstanceOf(ApiResult::class, $result);
        self::assertTrue($result->ok());
        self::assertInstanceOf(Widget::class, $result->data);
        self::assertSame('Example widget', $result->data->name);
        self::assertSame(WidgetStatus::active, $result->data->status);

        $fake->assertSent(HttpMethod::GET->value, '/v1/widgets/01H');
    }

    #[Test]
    public function an_untouched_widget_status_falls_back_to_unknown(): void
    {
        $widget = Widget::from([Widget::id => '01H']);

        self::assertSame(WidgetStatus::unknown, $widget->status);
    }

    #[Test]
    public function list_widgets(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'data' => [
                'widgets' => [['id' => 'wid-01', 'name' => 'First', 'status' => 'active']],
                'Pagination' => ['total' => 1, 'current_page' => 1],
            ],
        ]) ?: ''));

        $result = $api->listWidgets([Options::query => [Query::per_page => 10]]);

        self::assertTrue($result->ok());
        self::assertInstanceOf(WidgetsResponse::class, $result->data);
        self::assertInstanceOf(Widget::class, $result->data->widgets[0]);
        self::assertSame('First', $result->data->widgets[0]->name);
        self::assertInstanceOf(Pagination::class, $result->data->Pagination);
        self::assertSame(1, $result->data->Pagination->total);

        self::assertSame(self::url . '/v1/widgets?per_page=10', $fake->recorded()[0]['url']);
    }

    #[Test]
    public function create_widget(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(201, [], json_encode(['data' => ['id' => 'new-01']]) ?: ''));

        $result = $api->createWidget(CreateWidgetRequest::from([
            CreateWidgetRequest::name => 'Created',
            CreateWidgetRequest::status => WidgetStatus::active->value,
        ]));

        self::assertSame(201, $result->status());
        self::assertInstanceOf(Widget::class, $result->data);

        $fake->assertSent(HttpMethod::POST->value, '/v1/widgets');
        self::assertSame(
            ['name' => 'Created', 'status' => 'active'],
            $fake->recorded()[0]['options']['json'],
        );
    }

    #[Test]
    public function update_widget(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => ['id' => '01H', 'name' => 'Renamed']]) ?: ''));

        $result = $api->updateWidget('01H', UpdateWidgetRequest::from([
            UpdateWidgetRequest::name => 'Renamed',
        ]));

        self::assertSame('Renamed', $result->data->name);

        $fake->assertSent(HttpMethod::PATCH->value, '/v1/widgets/01H');
        self::assertSame(['name' => 'Renamed'], $fake->recorded()[0]['options']['json']);
    }

    #[Test]
    public function delete_widget_declares_no_response_model(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['message' => 'Widget deleted successfully.']) ?: ''));

        $result = $api->deleteWidget('wid-01');

        self::assertTrue($result->ok());
        self::assertNull($result->data);
        self::assertNull($result->errors);

        $fake->assertSent(HttpMethod::DELETE->value, '/v1/widgets/wid-01');
    }

    #[Test]
    public function list_widget_tags_hydrates_a_bare_json_array(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            ['id' => '01H1', 'name' => 'featured'],
            ['id' => '01H2', 'name' => 'clearance'],
        ]) ?: ''));

        $result = $api->listWidgetTags('01H');

        self::assertCount(2, $result->data);
        self::assertContainsOnlyInstancesOf(WidgetTag::class, $result->data);
        self::assertSame(['featured', 'clearance'], array_map(
            static fn(WidgetTag $tag): ?string => $tag->name,
            $result->data,
        ));

        $fake->assertSent(HttpMethod::GET->value, '/v1/widgets/01H/tags');
    }

    #[Test]
    public function a_failure_hydrates_the_errors_model(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(404, [], json_encode(['message' => 'Widget not found']) ?: ''));

        $result = $api->getWidget('missing');

        self::assertTrue($result->failed());
        self::assertSame('Widget not found', $result->errors->message);
    }

    // ──────────────────────────────────────────────────────────────
    // The example factories
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_example_factories_produce_their_documented_defaults(): void
    {
        $widget = WidgetFactory::factory()->make();
        self::assertSame('01HABCDEF000000000000000', $widget->id);
        self::assertSame('Example widget', $widget->name);
        self::assertSame(WidgetStatus::active, $widget->status);

        $tag = WidgetTagFactory::factory()->make();
        self::assertInstanceOf(WidgetTag::class, $tag);
        self::assertSame('featured', $tag->name);

        $create = CreateWidgetRequestFactory::factory()->make();
        self::assertInstanceOf(CreateWidgetRequest::class, $create);
        self::assertSame('Example widget', $create->name);
        self::assertSame(WidgetStatus::active, $create->status);

        $update = UpdateWidgetRequestFactory::factory()->make();
        self::assertInstanceOf(UpdateWidgetRequest::class, $update);
        self::assertSame('Renamed widget', $update->name);
        self::assertSame(WidgetStatus::archived, $update->status);
    }

    #[Test]
    public function the_collection_factory_composes_the_resource_factory(): void
    {
        $response = WidgetsResponseFactory::factory()->make();

        self::assertInstanceOf(WidgetsResponse::class, $response);
        self::assertInstanceOf(Widget::class, $response->widgets[0]);
        self::assertSame('Example widget', $response->widgets[0]->name);
        self::assertInstanceOf(Pagination::class, $response->Pagination);
        self::assertSame(1, $response->Pagination->total);

        // The shipped `PaginationFactory` is named here and nowhere else: a
        // document declaring its own `Pagination` schema replaces the model, so
        // the shared suite composes `FixturePaginationFactory` instead.
        self::assertSame(1, $response->Pagination->current_page);
        self::assertSame(1, $response->Pagination->last_page);
        self::assertSame(10, $response->Pagination->per_page);
    }

    #[Test]
    public function a_factory_built_body_round_trips_through_the_fake(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], WidgetsResponseFactory::factory()
            ->set(WidgetsResponse::widgets, [
                WidgetFactory::factory()->set(Widget::name, 'First')->context(),
            ])
            ->json() ?: ''));

        $result = $api->listWidgets();

        self::assertSame('First', $result->data->widgets[0]->name);
        $fake->assertSent(HttpMethod::GET->value, ApiRoute::widgets->value);
    }
}
