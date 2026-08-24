<?php

namespace Unit;

use PHPUnit\Framework\Attributes\Test;
use Tests\Fixtures\Factories\FixtureCreateThingRequestFactory;
use Tests\Fixtures\Factories\FixturePaginationFactory;
use Tests\Fixtures\Factories\FixtureThingFactory;
use Tests\Fixtures\Factories\FixtureThingsResponseFactory;
use Tests\Fixtures\Factories\FixtureThingTagFactory;
use Tests\Fixtures\Factories\FixtureUpdateThingRequestFactory;
use Tests\Fixtures\FixtureRoute;
use Tests\Fixtures\Models\FixtureCreateThingRequest;
use Tests\Fixtures\Models\FixturePagination;
use Tests\Fixtures\Models\FixtureThing;
use Tests\Fixtures\Models\FixtureThingsResponse;
use Tests\Fixtures\Models\FixtureThingStatus;
use Tests\Fixtures\Models\FixtureThingTag;
use Tests\Fixtures\Models\FixtureUpdateThingRequest;
use Tests\TestCase;
use Zerotoprod\Sdk\ApiResult;
use Zerotoprod\Sdk\Factories\ErrorsFactory;
use Zerotoprod\Sdk\Factories\SdkConfigFactory;
use Zerotoprod\Sdk\Internal\Fake;
use Zerotoprod\Sdk\Internal\HttpMethod;
use Zerotoprod\Sdk\Models\Errors;
use Zerotoprod\Sdk\Response;
use Zerotoprod\Sdk\SdkApi;
use Zerotoprod\Sdk\SdkConfig;

/**
 * Factory semantics — `set()`, `merge()`, `context()`, composition, and feeding
 * a factory-built body through the fake transport.
 *
 * The model factories used here live in `tests/Fixtures/Factories`. The only
 * package factories it names are ones no OpenAPI document can reshape:
 * `ErrorsFactory`, whose model the shared client itself depends on, and the
 * config factory. The example domain's factories -- and `PaginationFactory`,
 * which a document declaring its own `Pagination` schema overwrites -- are
 * covered in `ExampleDomainTest`, which `php init` deletes.
 */
class FactoryTest extends TestCase
{
    /**
     * @return array{SdkApi<Response>, Fake}
     */
    private function fake(): array
    {
        return SdkApi::fake(
            SdkConfigFactory::factory()
                ->set(SdkConfig::route_enum, FixtureRoute::class)
                ->context(),
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Building models: defaults, initial context, set(), merge()
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function default_make_returns_fully_populated_model(): void
    {
        $thing = FixtureThingFactory::factory()->make();

        self::assertInstanceOf(FixtureThing::class, $thing);
        self::assertSame('01HABCDEF000000000000000', $thing->id);
        self::assertSame('Example thing', $thing->name);
        self::assertSame(FixtureThingStatus::active, $thing->status);
    }

    #[Test]
    public function initial_context_passed_to_factory_constructor(): void
    {
        $thing = FixtureThingFactory::factory([
            FixtureThing::id => 'initial-01',
            FixtureThing::name => 'Initial',
        ])->make();

        self::assertSame('initial-01', $thing->id);
        self::assertSame('Initial', $thing->name);
        self::assertSame(FixtureThingStatus::active, $thing->status); // default retained
    }

    #[Test]
    public function set_single_key_value(): void
    {
        $thing = FixtureThingFactory::factory()
            ->set(FixtureThing::name, 'Single')
            ->make();

        self::assertSame('Single', $thing->name);
    }

    #[Test]
    public function set_with_array_syntax(): void
    {
        $thing = FixtureThingFactory::factory()
            ->set([
                FixtureThing::id => 'array-01',
                FixtureThing::name => 'Array form',
            ])
            ->make();

        self::assertSame('array-01', $thing->id);
        self::assertSame('Array form', $thing->name);
    }

    #[Test]
    public function set_with_closure_derives_from_current_context(): void
    {
        $thing = FixtureThingFactory::factory()
            ->set(FixtureThing::id, 'thg-01')
            // Closure receives current context and returns overrides
            ->set(static fn(array $ctx): array => [
                FixtureThing::name => 'Thing ' . $ctx[FixtureThing::id],
            ])
            ->make();

        self::assertSame('Thing thg-01', $thing->name);
    }

    #[Test]
    public function merge_layers_additional_values_onto_context(): void
    {
        $thing = FixtureThingFactory::factory()
            ->merge([FixtureThing::name => 'Merged'])
            ->merge([FixtureThing::status => FixtureThingStatus::archived->value])
            ->make();

        self::assertSame('Merged', $thing->name);
        self::assertSame(FixtureThingStatus::archived, $thing->status);
        self::assertSame('01HABCDEF000000000000000', $thing->id); // untouched
    }

    #[Test]
    public function chained_sets_compose_fluently(): void
    {
        $thing = FixtureThingFactory::factory()
            ->set(FixtureThing::id, 'thg-99')
            ->set(FixtureThing::name, 'Chained')
            ->set(FixtureThing::status, FixtureThingStatus::archived->value)
            ->set(FixtureThing::updated_at, '2026-02-02T00:00:00Z')
            ->make();

        self::assertSame('thg-99', $thing->id);
        self::assertSame('Chained', $thing->name);
        self::assertSame(FixtureThingStatus::archived, $thing->status);
        self::assertSame('2026-02-02T00:00:00Z', $thing->updated_at);
    }

    // ──────────────────────────────────────────────────────────────
    // context() — resolved array without instantiating the model
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function context_returns_resolved_array_without_hydration(): void
    {
        $array = FixtureThingFactory::factory()
            ->set(FixtureThing::name, 'Array only')
            ->context();

        self::assertIsArray($array);
        self::assertSame('Array only', $array[FixtureThing::name]);
    }

    #[Test]
    public function context_is_the_preferred_way_to_compose_factories(): void
    {
        // FixtureThingsResponseFactory uses FixtureThingFactory::factory()->context()
        // in its definition() — avoiding the hydrate → toArray round trip.
        $response = FixtureThingsResponseFactory::factory()->make();

        self::assertInstanceOf(FixtureThingsResponse::class, $response);
        self::assertInstanceOf(FixtureThing::class, $response->things[0]);
        self::assertSame('Example thing', $response->things[0]->name);
        self::assertInstanceOf(FixturePagination::class, $response->Pagination);
        self::assertSame(1, $response->Pagination->total);
    }

    // ──────────────────────────────────────────────────────────────
    // Integration: factories + SdkApi::fake()
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function config_factory_feeds_the_api_constructor(): void
    {
        $api = new SdkApi(
            SdkConfigFactory::factory()->make(),
        );

        self::assertSame('https://api.example.com', $api->config->url);
    }

    #[Test]
    public function factories_replace_raw_array_fixtures_in_transport_tests(): void
    {
        // Build a config + a paginated response body, entirely via factories
        [$api, $fake] = $this->fake();

        // ->json() returns the resolved context directly as JSON — no separate json_encode() needed
        $fake->queue(new Response(200, [], FixtureThingsResponseFactory::factory()
            ->set(FixtureThingsResponse::things, [
                FixtureThingFactory::factory()->set(FixtureThing::name, 'First')->context(),
                FixtureThingFactory::factory()->set(FixtureThing::name, 'Second')->context(),
            ])
            ->set(FixtureThingsResponse::Pagination, FixturePaginationFactory::factory()->set(FixturePagination::total, 2)->context())
            ->json() ?: ''));

        $result = $api->listThings();

        self::assertInstanceOf(ApiResult::class, $result);
        self::assertTrue($result->ok());
        self::assertCount(2, $result->data->things);
        self::assertSame('First', $result->data->things[0]->name);
        self::assertSame('Second', $result->data->things[1]->name);
        self::assertSame(2, $result->data->Pagination->total);

        $fake->assertSent(HttpMethod::GET->value, FixtureRoute::things->value);
    }

    #[Test]
    public function a_factory_builds_the_elements_of_a_bare_array_response(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            FixtureThingTagFactory::factory()->set(FixtureThingTag::name, 'featured')->context(),
            FixtureThingTagFactory::factory()->set(FixtureThingTag::name, 'clearance')->context(),
        ]) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertCount(2, $result->data);
        self::assertSame(['featured', 'clearance'], array_map(
            static fn(FixtureThingTag $tag): ?string => $tag->name,
            $result->data,
        ));
    }

    #[Test]
    public function error_response_can_be_built_from_errors_factory(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(422, [], ErrorsFactory::factory()
            ->set(Errors::message, 'validation failed')
            ->set(Errors::errors, ['name' => ['The name field is required.']])
            ->json() ?: ''));

        $result = $api->updateThing('01H', ['name' => '']);

        self::assertTrue($result->failed());
        self::assertSame(422, $result->status());
        self::assertSame('validation failed', $result->errors->message);
        self::assertSame(['name' => ['The name field is required.']], $result->errors->errors);
    }

    // ──────────────────────────────────────────────────────────────
    // Request-body factories → fluent updates with typed models
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function request_factory_builds_typed_payload_for_mutations(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], FixtureThingFactory::factory()
            ->set(FixtureThing::name, 'Renamed')
            ->json() ?: ''));

        $body = FixtureUpdateThingRequestFactory::factory()
            ->set(FixtureUpdateThingRequest::name, 'Renamed')
            ->make();

        $result = $api->updateThing('01H', $body);

        self::assertTrue($result->ok());
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame('Renamed', $result->data->name);

        // The typed request model serialised into the JSON body — enums are
        // unwrapped to their backing value and null fields are omitted.
        self::assertSame(
            ['name' => 'Renamed', 'status' => 'archived'],
            $fake->recorded()[0]['options']['json'],
        );
    }

    #[Test]
    public function create_request_factory_defaults_produce_valid_request(): void
    {
        $request = FixtureCreateThingRequestFactory::factory()->make();

        self::assertInstanceOf(FixtureCreateThingRequest::class, $request);
        self::assertSame('Example thing', $request->name);
        self::assertSame(FixtureThingStatus::active, $request->status);
    }

    #[Test]
    public function update_request_factory_defaults_produce_valid_request(): void
    {
        $request = FixtureUpdateThingRequestFactory::factory()->make();

        self::assertInstanceOf(FixtureUpdateThingRequest::class, $request);
        self::assertSame('Renamed thing', $request->name);
        self::assertSame(FixtureThingStatus::archived, $request->status);
    }

    // ──────────────────────────────────────────────────────────────
    // The package factories generation never deletes
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function errors_factory_defaults(): void
    {
        $errors = ErrorsFactory::factory()->make();

        self::assertInstanceOf(Errors::class, $errors);
        self::assertSame('Something went wrong', $errors->message);
        self::assertSame([], $errors->errors);
    }

    #[Test]
    public function pagination_factory_defaults(): void
    {
        $pagination = FixturePaginationFactory::factory()->make();

        self::assertInstanceOf(FixturePagination::class, $pagination);
        self::assertSame(1, $pagination->current_page);
        self::assertSame(10, $pagination->per_page);
        self::assertNull($pagination->next_page_url);
    }

    #[Test]
    public function config_factory_defaults(): void
    {
        $config = SdkConfigFactory::factory()->make();

        self::assertInstanceOf(SdkConfig::class, $config);
        self::assertSame('https://api.example.com', $config->url);
        self::assertSame('Zerotoprod\\Sdk\\Models', $config->model_namespace);
    }
}
