<?php

namespace Unit;

use BadMethodCallException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\Fixtures\AltFixtureRoute;
use Tests\Fixtures\FixtureRoute;
use Tests\Fixtures\Models\FixtureCreateThingRequest;
use Tests\Fixtures\Models\FixtureDeleteLabelRequest;
use Tests\Fixtures\Models\FixturePagination;
use Tests\Fixtures\Models\FixtureThing;
use Tests\Fixtures\Models\FixtureThingsResponse;
use Tests\Fixtures\Models\FixtureThingStatus;
use Tests\Fixtures\Models\FixtureThingTag;
use Tests\Fixtures\Models\FixtureUpdateThingRequest;
use Tests\TestCase;
use Zerotoprod\Sdk\ApiResult;
use Zerotoprod\Sdk\HttpTransport;
use Zerotoprod\Sdk\Internal\AdminApi;
use Zerotoprod\Sdk\Internal\Fake;
use Zerotoprod\Sdk\Internal\HttpMethod;
use Zerotoprod\Sdk\Internal\Route;
use Zerotoprod\Sdk\Models\Errors;
use Zerotoprod\Sdk\Models\Query;
use Zerotoprod\Sdk\Options;
use Zerotoprod\Sdk\Response;
use Zerotoprod\Sdk\SdkApi;
use Zerotoprod\Sdk\SdkConfig;

/**
 * The dispatcher, `Route`, `Response`, `ApiResult`, `Transformable` and the fake
 * transport — the hand-written code every derived package inherits.
 *
 * Every request here is dispatched against {@see FixtureRoute} rather than the
 * package's own `ApiRoute`, because `composer generate-sdk` replaces `ApiRoute`
 * wholesale. A test that named a shipped example route would die in the first
 * generated package, and these are the tests that have to keep merging
 * downstream. The shipped example domain is smoke-tested on its own in
 * `ExampleDomainTest`, which `php init` deletes.
 */
class SdkApiTest extends TestCase
{
    private const url = 'https://api.example.com';

    /**
     * A faked client wired to the fixture route enum.
     *
     * @param  array<string, mixed>  $config
     * @return array{SdkApi<Response>, Fake}
     */
    private function fake(array $config = []): array
    {
        return SdkApi::fake([
            SdkConfig::url => self::url,
            SdkConfig::route_enum => FixtureRoute::class,
            ...$config,
        ]);
    }

    // ──────────────────────────────────────────────────────────────
    // Construction
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function instantiates_from_array(): void
    {
        $api = new SdkApi([
            SdkConfig::url => self::url,
        ]);

        self::assertInstanceOf(SdkApi::class, $api);
        self::assertSame(self::url, $api->config->url);
    }

    #[Test]
    public function instantiates_from_config_object(): void
    {
        $config = SdkConfig::from([
            SdkConfig::url => self::url,
        ]);

        $api = new SdkApi($config);

        self::assertSame($config, $api->config);
    }

    #[Test]
    public function fake_returns_the_api_and_the_fake_transport(): void
    {
        [$api, $fake] = SdkApi::fake([SdkConfig::url => self::url]);

        self::assertInstanceOf(SdkApi::class, $api);
        self::assertInstanceOf(Fake::class, $fake);
        self::assertSame(self::url, $api->config->url);
    }

    // ──────────────────────────────────────────────────────────────
    // route_enum — which enum the dispatcher resolves against
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function the_configured_route_enum_decides_which_methods_exist(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['id' => '01H']) ?: ''));

        $api->getThing('01H');

        self::assertSame(self::url . '/v1/things/01H', $fake->recorded()[0]['url']);
    }

    #[Test]
    public function two_route_enums_declaring_the_same_method_name_coexist(): void
    {
        // The attribute scan is memoized for the lifetime of the process, so the
        // cache has to be keyed by enum class: dispatching one enum must not
        // decide what the next client sees.
        [$one, $fakeOne] = $this->fake();
        [$two, $fakeTwo] = SdkApi::fake([
            SdkConfig::url => self::url,
            SdkConfig::route_enum => AltFixtureRoute::class,
        ]);

        $fakeOne->queue(new Response(200, [], '{}'));
        $fakeTwo->queue(new Response(200, [], '{}'));

        $one->getThing('01H');
        $two->getThing('01H');

        self::assertSame(self::url . '/v1/things/01H', $fakeOne->recorded()[0]['url']);
        self::assertSame(self::url . '/v2/alt-things/01H', $fakeTwo->recorded()[0]['url']);
    }

    #[Test]
    public function a_method_from_another_route_enum_is_not_dispatchable(): void
    {
        [$api] = SdkApi::fake([
            SdkConfig::url => self::url,
            SdkConfig::route_enum => AltFixtureRoute::class,
        ]);

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method listThings does not exist.');

        /** @phpstan-ignore-next-line declared on FixtureRoute, not AltFixtureRoute */
        $api->listThings();
    }

    // ──────────────────────────────────────────────────────────────
    // getThing — path params, success + failure hydration
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function get_thing(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'message' => 'ThingResource',
            'errors' => [],
            'data' => [
                'id' => '01H',
                'name' => 'Example thing',
                'status' => 'active',
                'created_at' => '2026-01-01T00:00:00Z',
                'updated_at' => '2026-01-01T00:00:00Z',
            ],
            'type' => 'ThingResource',
        ]) ?: ''));

        $result = $api->getThing('01H');

        self::assertInstanceOf(ApiResult::class, $result);
        self::assertTrue($result->ok());
        self::assertFalse($result->failed());
        self::assertSame(200, $result->status());
        self::assertNull($result->errors);
        self::assertInstanceOf(Response::class, $result->response);
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame('01H', $result->data->id);
        self::assertSame('Example thing', $result->data->name);
        self::assertSame(FixtureThingStatus::active, $result->data->status);

        $fake->assertSent(HttpMethod::GET->value, '/v1/things/01H');

        $recorded = $fake->recorded()[0];
        self::assertSame(self::url . '/v1/things/01H', $recorded['url']);
        self::assertArrayNotHasKey('json', $recorded['options']);
    }

    #[Test]
    public function get_thing_hydrates_from_the_whole_body_when_no_data_envelope_is_present(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'id' => '01H',
            'name' => 'Unenveloped',
        ]) ?: ''));

        $result = $api->getThing('01H');

        self::assertTrue($result->ok());
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame('Unenveloped', $result->data->name);
        // Untouched enum property falls back to its declared default.
        self::assertSame(FixtureThingStatus::unknown, $result->data->status);
    }

    #[Test]
    public function get_thing_returns_errors_on_failure(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(404, [], json_encode([
            'success' => false,
            'message' => 'Thing not found',
            'errors' => ['Thing not found'],
            'data' => [],
            'type' => 'Error',
        ]) ?: ''));

        $result = $api->getThing('missing');

        self::assertTrue($result->failed());
        self::assertFalse($result->ok());
        self::assertSame(404, $result->status());
        self::assertNull($result->data);
        self::assertNotNull($result->errors);
        self::assertSame('Thing not found', $result->errors->message);
        self::assertSame(['Thing not found'], $result->errors->errors);
    }

    #[Test]
    public function omitted_path_param_renders_as_an_empty_string(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => []]) ?: ''));

        $api->getThing();

        self::assertSame(self::url . '/v1/things/', $fake->recorded()[0]['url']);
    }

    // ──────────────────────────────────────────────────────────────
    // listThings — the Query DSL through the dispatcher
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function list_things(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'message' => 'PaginatedThingsResource',
            'errors' => [],
            'data' => [
                'things' => [
                    ['id' => 'thg-01', 'name' => 'First', 'status' => 'active'],
                    ['id' => 'thg-02', 'name' => 'Second', 'status' => 'archived'],
                ],
                'Pagination' => [
                    'current_page' => 1,
                    'last_page' => 1,
                    'per_page' => 10,
                    'total' => 2,
                    'next_page_url' => null,
                    'prev_page_url' => null,
                ],
            ],
            'type' => 'PaginatedThingsResource',
        ]) ?: ''));

        $result = $api->listThings([
            Options::query => [Query::where => ['name', 'First'], Query::per_page => '10', Query::with => 'parts'],
        ]);

        self::assertInstanceOf(ApiResult::class, $result);
        self::assertTrue($result->ok());
        self::assertInstanceOf(FixtureThingsResponse::class, $result->data);
        self::assertCount(2, $result->data->things);
        self::assertInstanceOf(FixtureThing::class, $result->data->things[0]);
        self::assertSame('First', $result->data->things[0]->name);
        self::assertSame('thg-02', $result->data->things[1]->id);
        self::assertSame(FixtureThingStatus::archived, $result->data->things[1]->status);

        self::assertInstanceOf(FixturePagination::class, $result->data->Pagination);
        self::assertSame(2, $result->data->Pagination->total);

        $fake->assertSent(HttpMethod::GET->value, '/v1/things');

        self::assertSame(
            self::url . '/v1/things?where%5B0%5D%5B0%5D=name&where%5B0%5D%5B1%5D=First&per_page=10&with=parts',
            $fake->recorded()[0]['url'],
        );
    }

    #[Test]
    public function list_things_with_nested_with_and_three_tuple_where(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'data' => ['things' => [], 'Pagination' => null],
        ]) ?: ''));

        $result = $api->listThings([
            Options::query => [
                Query::where => ['name', 'LIKE', '%thing%'],
                Query::with => ['parts' => ['vendor'], 'owner'],
            ],
        ]);

        self::assertInstanceOf(FixtureThingsResponse::class, $result->data);
        self::assertSame([], $result->data->things);
        self::assertNull($result->data->Pagination);

        $url = $fake->recorded()[0]['url'];
        self::assertStringContainsString('where%5B0%5D%5B0%5D=name', $url);
        self::assertStringContainsString('where%5B0%5D%5B1%5D=LIKE', $url);
        self::assertStringContainsString('where%5B0%5D%5B2%5D=%25thing%25', $url);
        self::assertStringContainsString('with=parts.vendor%2Cowner', $url);
    }

    #[Test]
    public function list_things_with_where_in_where_not_in_and_fields(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => ['things' => []]]) ?: ''));

        $api->listThings([
            Options::query => [
                Query::where_in => ['status' => ['active', 'archived']],
                Query::where_not_in => ['status' => ['unknown']],
                Query::fields => ['things' => ['id', 'name']],
            ],
        ]);

        $url = $fake->recorded()[0]['url'];
        self::assertStringContainsString('where_in%5Bstatus%5D%5B0%5D=active', $url);
        self::assertStringContainsString('where_in%5Bstatus%5D%5B1%5D=archived', $url);
        self::assertStringContainsString('where_not_in%5Bstatus%5D%5B0%5D=unknown', $url);
        self::assertStringContainsString('fields%5Bthings%5D=id%2Cname', $url);
    }

    #[Test]
    public function list_things_without_a_query_sends_a_bare_url(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => ['things' => []]]) ?: ''));

        $api->listThings();

        self::assertSame(self::url . '/v1/things', $fake->recorded()[0]['url']);
    }

    #[Test]
    public function list_things_returns_errors_on_failure(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(422, [], json_encode([
            'success' => false,
            'message' => 'validation failed',
            'errors' => ['where' => ['The where field must be an array.']],
            'data' => [],
            'type' => 'Error',
        ]) ?: ''));

        $result = $api->listThings([Options::query => ['where[invalid]' => 'x']]);

        self::assertTrue($result->failed());
        self::assertSame(422, $result->status());
        self::assertNotNull($result->errors);
        self::assertSame('validation failed', $result->errors->message);
        self::assertSame(['where' => ['The where field must be an array.']], $result->errors->errors);
    }

    // ──────────────────────────────────────────────────────────────
    // listThingTags — a bare JSON array via listOf:
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function list_thing_tags_hydrates_every_element_of_a_bare_array(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            ['id' => '01H1', 'name' => 'featured', 'created_at' => '2026-01-01T00:00:00Z'],
            ['id' => '01H2', 'name' => 'clearance', 'created_at' => '2026-01-02T00:00:00Z'],
        ]) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertInstanceOf(ApiResult::class, $result);
        self::assertTrue($result->ok());
        self::assertNull($result->errors);
        self::assertIsArray($result->data);
        self::assertCount(2, $result->data);
        self::assertContainsOnlyInstancesOf(FixtureThingTag::class, $result->data);
        self::assertSame('featured', $result->data[0]->name);
        self::assertSame('01H2', $result->data[1]->id);
        self::assertSame([0, 1], array_keys($result->data));

        $fake->assertSent(HttpMethod::GET->value, '/v1/things/01H/tags');
    }

    #[Test]
    public function an_empty_bare_array_hydrates_to_an_empty_list_not_null(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], '[]'));

        $result = $api->listThingTags('01H');

        self::assertSame([], $result->data);
        self::assertNull($result->errors);
    }

    #[Test]
    public function a_list_response_unwraps_the_data_envelope_like_an_object_response_does(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'data' => [['id' => '01H1', 'name' => 'featured']],
        ]) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertCount(1, $result->data);
        self::assertInstanceOf(FixtureThingTag::class, $result->data[0]);
        self::assertSame('featured', $result->data[0]->name);
    }

    #[Test]
    public function a_list_element_that_is_not_an_object_is_skipped_rather_than_guessed_at(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            ['id' => '01H1'],
            'not-an-object',
            17,
            null,
            ['id' => '01H2'],
        ]) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertCount(2, $result->data);
        self::assertSame(['01H1', '01H2'], array_map(
            static fn(FixtureThingTag $tag): ?string => $tag->id,
            $result->data,
        ));
    }

    #[Test]
    public function a_list_route_whose_body_is_not_an_array_yields_an_empty_list(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['id' => '01H1', 'name' => 'featured']) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertSame([], $result->data);
        // The body is never lost — it is still on the response.
        self::assertSame(['id' => '01H1', 'name' => 'featured'], $result->response->json());
    }

    #[Test]
    public function a_list_route_hydrates_errors_on_failure_exactly_as_an_object_route_does(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(404, [], json_encode(['message' => 'Thing not found']) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertTrue($result->failed());
        self::assertNull($result->data);
        self::assertSame('Thing not found', $result->errors->message);
    }

    #[Test]
    public function the_raw_option_bypasses_list_hydration(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([['id' => '01H1']]) ?: ''));

        $response = $api->listThingTags('01H', [Options::raw => true]);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame([['id' => '01H1']], $response->json());
    }

    // ──────────────────────────────────────────────────────────────
    // Request bodies — raw arrays and model instances
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function update_thing_with_an_array_body(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'data' => ['id' => '01H', 'name' => 'Renamed', 'status' => 'active'],
        ]) ?: ''));

        $result = $api->updateThing('01H', ['name' => 'Renamed']);

        self::assertTrue($result->ok());
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame('Renamed', $result->data->name);

        $fake->assertSent(HttpMethod::PATCH->value, '/v1/things/01H');

        $recorded = $fake->recorded()[0];
        self::assertSame(['name' => 'Renamed'], $recorded['options']['json']);
    }

    #[Test]
    public function update_thing_with_a_model_body_serializes_via_to_array(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'data' => ['id' => '01H', 'name' => 'Renamed', 'status' => 'archived'],
        ]) ?: ''));

        $result = $api->updateThing('01H', FixtureUpdateThingRequest::from([
            FixtureUpdateThingRequest::name => 'Renamed',
            FixtureUpdateThingRequest::status => FixtureThingStatus::archived->value,
        ]));

        self::assertTrue($result->ok());
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame(FixtureThingStatus::archived, $result->data->status);

        // Backed enums are unwrapped to their scalar value; nulls are skipped.
        self::assertSame(
            ['name' => 'Renamed', 'status' => 'archived'],
            $fake->recorded()[0]['options']['json'],
        );
    }

    #[Test]
    public function update_thing_without_a_body_sends_an_empty_json_payload(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => ['id' => '01H']]) ?: ''));

        $api->updateThing('01H');

        self::assertSame([], $fake->recorded()[0]['options']['json']);
    }

    #[Test]
    public function create_thing_maps_the_body_as_the_first_positional_argument(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(201, [], json_encode([
            'success' => true,
            'data' => ['id' => 'new-01', 'name' => 'Created', 'status' => 'active'],
        ]) ?: ''));

        $result = $api->createThing(['name' => 'Created', 'status' => 'active']);

        self::assertTrue($result->ok());
        self::assertSame(201, $result->status());
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame('new-01', $result->data->id);

        $fake->assertSent(HttpMethod::POST->value, '/v1/things');

        $recorded = $fake->recorded()[0];
        self::assertSame(self::url . '/v1/things', $recorded['url']);
        self::assertSame(['name' => 'Created', 'status' => 'active'], $recorded['options']['json']);
    }

    #[Test]
    public function create_thing_with_a_model_body(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(201, [], json_encode(['data' => ['id' => 'new-01']]) ?: ''));

        $api->createThing(FixtureCreateThingRequest::from([
            FixtureCreateThingRequest::name => 'Created',
        ]));

        self::assertSame(['name' => 'Created'], $fake->recorded()[0]['options']['json']);
    }

    #[Test]
    public function create_thing_returns_errors_on_failure(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(422, [], json_encode([
            'message' => 'validation failed',
            'errors' => ['name' => ['The name field is required.']],
        ]) ?: ''));

        $result = $api->createThing([]);

        self::assertTrue($result->failed());
        self::assertSame(422, $result->status());
        self::assertNotNull($result->errors);
        self::assertSame(['name' => ['The name field is required.']], $result->errors->errors);
    }

    // ──────────────────────────────────────────────────────────────
    // A route with no declared response model
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function delete_thing_returns_an_api_result_with_null_data(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'message' => 'Thing deleted successfully.',
            'errors' => [],
            'data' => [],
            'type' => '',
        ]) ?: ''));

        $result = $api->deleteThing('thg-01');

        self::assertInstanceOf(ApiResult::class, $result);
        self::assertTrue($result->ok());
        self::assertNull($result->data);
        self::assertNull($result->errors);

        $fake->assertSent(HttpMethod::DELETE->value, '/v1/things/thg-01');
        self::assertArrayNotHasKey('json', $fake->recorded()[0]['options']);
    }

    #[Test]
    public function delete_thing_returns_errors_on_failure(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(404, [], json_encode([
            'success' => false,
            'message' => 'Thing not found',
            'errors' => ['Thing not found'],
            'data' => [],
            'type' => 'Error',
        ]) ?: ''));

        $result = $api->deleteThing('missing');

        self::assertTrue($result->failed());
        self::assertSame(404, $result->status());
        self::assertNotNull($result->errors);
        self::assertSame('Thing not found', $result->errors->message);
    }

    // ──────────────────────────────────────────────────────────────
    // Two path params, and a DELETE that declares a request body
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function every_path_param_is_substituted_in_order(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(204, [], ''));

        $api->deleteThingLabel('thg-01', 'urgent', ['reason' => 'mislabelled']);

        self::assertSame(
            self::url . '/v1/things/thg-01/labels/urgent',
            $fake->recorded()[0]['url'],
        );
    }

    #[Test]
    public function a_delete_that_declares_a_request_body_sends_one(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(204, [], ''));

        $result = $api->deleteThingLabel('thg-01', 'urgent', FixtureDeleteLabelRequest::from([
            FixtureDeleteLabelRequest::reason => 'mislabelled',
        ]));

        self::assertTrue($result->ok());
        self::assertNull($result->data);

        $fake->assertSent(HttpMethod::DELETE->value, '/v1/things/thg-01/labels/urgent');
        self::assertSame(['reason' => 'mislabelled'], $fake->recorded()[0]['options']['json']);
    }

    #[Test]
    public function a_delete_with_a_body_still_takes_options_after_the_body(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(204, [], ''));

        $api->deleteThingLabel('thg-01', 'urgent', [], [
            Options::query => [Query::per_page => 5],
            Options::headers => ['X-Request-Id' => 'req-1'],
        ]);

        $recorded = $fake->recorded()[0];
        self::assertSame(self::url . '/v1/things/thg-01/labels/urgent?per_page=5', $recorded['url']);
        self::assertSame([], $recorded['options']['json']);
        self::assertSame(['X-Request-Id' => 'req-1'], $recorded['options'][Options::headers]);
    }

    // ──────────────────────────────────────────────────────────────
    // Options — raw, headers, pass-through
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function raw_option_returns_the_transport_response_unwrapped(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, ['X-Trace' => 'abc'], json_encode(['data' => ['id' => '01H']]) ?: ''));

        $response = $api->getThing('01H', [Options::raw => true]);

        self::assertInstanceOf(Response::class, $response);
        self::assertSame(200, $response->status());
        self::assertSame('abc', $response->header('X-Trace'));
        // The flag is consumed by the dispatcher and never forwarded.
        self::assertArrayNotHasKey(Options::raw, $fake->recorded()[0]['options']);
    }

    #[Test]
    public function raw_option_returns_the_transport_response_on_failure_too(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(500, [], '{"message":"boom"}'));

        $response = $api->deleteThing('01H', [Options::raw => true]);

        self::assertInstanceOf(Response::class, $response);
        self::assertTrue($response->failed());
    }

    #[Test]
    public function headers_are_forwarded_to_the_transport(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => []]) ?: ''));

        $api->getThing('01H', [Options::headers => ['X-Request-Id' => 'req-1']]);

        self::assertSame(
            ['X-Request-Id' => 'req-1'],
            $fake->recorded()[0]['options'][Options::headers],
        );
    }

    #[Test]
    public function headers_are_merged_alongside_the_json_body_and_pass_through_options(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => ['id' => '01H']]) ?: ''));

        $api->updateThing(
            '01H',
            ['name' => 'Renamed'],
            [
                Options::headers => ['X-Request-Id' => 'req-1'],
                'timeout' => 5,
            ],
        );

        $options = $fake->recorded()[0]['options'];
        self::assertSame(['name' => 'Renamed'], $options['json']);
        self::assertSame(['X-Request-Id' => 'req-1'], $options[Options::headers]);
        self::assertSame(5, $options['timeout']);
    }

    #[Test]
    public function unknown_options_pass_through_to_the_transport_untouched(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(200, [], json_encode(['data' => []]) ?: ''));

        $api->getThing('01H', ['timeout' => 3, 'curl' => [CURLOPT_FAILONERROR => true]]);

        $options = $fake->recorded()[0]['options'];
        self::assertSame(3, $options['timeout']);
        self::assertSame([CURLOPT_FAILONERROR => true], $options['curl']);
    }

    #[Test]
    public function a_transport_returning_a_non_response_value_is_never_wrapped(): void
    {
        /** @var HttpTransport<array<string, mixed>> $transport */
        $transport = new class implements HttpTransport {
            /** @param  array<string, mixed>  $options */
            public function request(string $method, string $url, array $options = []): mixed
            {
                return ['method' => $method, 'url' => $url];
            }
        };

        $api = new SdkApi([
            SdkConfig::url => self::url,
            SdkConfig::route_enum => FixtureRoute::class,
        ], $transport);

        self::assertSame(
            ['method' => 'GET', 'url' => self::url . '/v1/things/01H'],
            $api->getThing('01H'),
        );
    }

    // ──────────────────────────────────────────────────────────────
    // model_namespace resolution
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function model_namespace_override_resolves_to_a_published_model(): void
    {
        [$api, $fake] = $this->fake([
            SdkConfig::model_namespace => 'Tests\\Fixtures\\Published',
        ]);

        $fake->queue(new Response(200, [], json_encode([
            'success' => true,
            'data' => [
                'things' => [['id' => '01H', 'name' => 'Published']],
                'Pagination' => ['total' => 1, 'current_page' => 1, 'last_page' => 1, 'per_page' => 1],
            ],
        ]) ?: ''));

        $result = $api->listThings();

        self::assertTrue($result->ok());
        self::assertInstanceOf(\Tests\Fixtures\Published\FixtureThingsResponse::class, $result->data);
        self::assertSame('published', $result->data->publishedMarker());
        self::assertInstanceOf(FixtureThing::class, $result->data->things[0]);
        self::assertSame('Published', $result->data->things[0]->name);
    }

    #[Test]
    public function model_namespace_override_falls_back_when_the_published_model_is_missing(): void
    {
        // The fixture namespace publishes FixtureThingsResponse but not
        // FixtureThing, so the class declared on the #[AdminApi] attribute is
        // used instead.
        [$api, $fake] = $this->fake([
            SdkConfig::model_namespace => 'Tests\\Fixtures\\Published',
        ]);

        $fake->queue(new Response(200, [], json_encode(['data' => ['id' => '01H', 'name' => 'Fallback']]) ?: ''));

        $result = $api->getThing('01H');

        self::assertTrue($result->ok());
        self::assertInstanceOf(FixtureThing::class, $result->data);
        self::assertSame('Fallback', $result->data->name);
    }

    #[Test]
    public function model_namespace_override_resolves_a_list_element_class(): void
    {
        [$api, $fake] = $this->fake([
            SdkConfig::model_namespace => 'Tests\\Fixtures\\Published',
        ]);

        $fake->queue(new Response(200, [], json_encode([['id' => '01H1', 'name' => 'Published']]) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertInstanceOf(\Tests\Fixtures\Published\FixtureThingTag::class, $result->data[0]);
        self::assertSame('published', $result->data[0]->publishedMarker());
        self::assertSame('Published', $result->data[0]->name);
    }

    #[Test]
    public function a_list_element_class_falls_back_when_the_namespace_publishes_none(): void
    {
        [$api, $fake] = $this->fake([
            SdkConfig::model_namespace => 'Tests\\Fixtures\\Unpublished',
        ]);

        $fake->queue(new Response(200, [], json_encode([['id' => '01H1', 'name' => 'Fallback']]) ?: ''));

        $result = $api->listThingTags('01H');

        self::assertInstanceOf(FixtureThingTag::class, $result->data[0]);
        self::assertSame('Fallback', $result->data[0]->name);
    }

    #[Test]
    public function errors_fall_back_to_the_package_model_when_the_namespace_publishes_none(): void
    {
        [$api, $fake] = $this->fake([
            SdkConfig::model_namespace => 'Tests\\Fixtures\\Published',
        ]);

        $fake->queue(new Response(500, [], json_encode([
            'message' => 'boom',
            'errors' => ['boom'],
        ]) ?: ''));

        $result = $api->getThing('01H');

        self::assertTrue($result->failed());
        self::assertInstanceOf(Errors::class, $result->errors);
        self::assertSame('boom', $result->errors->message);
    }

    #[Test]
    public function errors_hydrate_from_an_empty_body(): void
    {
        [$api, $fake] = $this->fake();

        $fake->queue(new Response(503, [], ''));

        $result = $api->getThing('01H');

        self::assertTrue($result->failed());
        self::assertSame(503, $result->status());
        self::assertNotNull($result->errors);
        self::assertNull($result->errors->message);
        self::assertSame([], $result->errors->errors);
    }

    // ──────────────────────────────────────────────────────────────
    // Unknown methods
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function unknown_method_throws_a_bad_method_call_exception(): void
    {
        [$api] = $this->fake();

        $this->expectException(BadMethodCallException::class);
        $this->expectExceptionMessage('Method nopeNotHere does not exist.');

        /** @phpstan-ignore-next-line intentionally undeclared method */
        $api->nopeNotHere('01H');
    }

    // ──────────────────────────────────────────────────────────────
    // Routes
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function route_call_static_renders_a_route_with_query_params(): void
    {
        self::assertSame('/v1/things?per_page=50', FixtureRoute::things(['per_page' => 50])->render());
        self::assertSame('/v1/things', FixtureRoute::things()->render());
    }

    #[Test]
    public function route_for_accepts_any_string_backed_enum_case(): void
    {
        $route = Route::for(FixtureRoute::thing, [], ['id' => 'thg-01']);

        self::assertSame('/v1/things/thg-01', $route->render());
        self::assertSame('/v1/things/{id}', $route->route);
        self::assertSame(['id' => 'thg-01'], $route->path_params);
    }

    #[Test]
    public function route_render_replaces_every_path_param(): void
    {
        $route = Route::for(
            FixtureRoute::thingLabel,
            ['per_page' => 5],
            ['id' => 'thg-01', 'label' => 'urgent'],
        );

        self::assertSame('/v1/things/thg-01/labels/urgent?per_page=5', $route->render());
    }

    #[Test]
    public function route_render_drops_empty_query_params(): void
    {
        self::assertSame(
            '/v1/things?per_page=50',
            Route::for(FixtureRoute::things, ['per_page' => 50, 'with' => '', 'where' => []])->render(),
        );
    }

    // ──────────────────────────────────────────────────────────────
    // Response
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function response_exposes_status_body_and_decoded_json(): void
    {
        $response = new Response(200, ['Content-Type' => 'application/json'], '{"things":[{"id":"01H"}]}');

        self::assertTrue($response->ok());
        self::assertFalse($response->failed());
        self::assertSame(200, $response->status());
        self::assertSame(['things' => [['id' => '01H']]], $response->json());
        self::assertSame([['id' => '01H']], $response->json('things'));
        self::assertNull($response->json('missing'));
        self::assertSame('fallback', $response->json('missing', 'fallback'));
        self::assertSame('{"things":[{"id":"01H"}]}', $response->body);
        self::assertSame('application/json', $response->header('Content-Type'));
    }

    #[Test]
    public function response_header_falls_back_to_a_lowercase_lookup(): void
    {
        $response = new Response(204, ['x-request-id' => 'req-1'], '');

        self::assertSame('req-1', $response->header('X-Request-Id'));
        self::assertNull($response->header('X-Missing'));
        self::assertSame([], $response->json());
        self::assertTrue($response->ok());
    }

    #[Test]
    public function response_reports_non_2xx_as_failed(): void
    {
        self::assertTrue((new Response(500, [], ''))->failed());
        self::assertFalse((new Response(500, [], ''))->ok());
        self::assertTrue((new Response(199, [], ''))->failed());
        self::assertTrue((new Response(300, [], ''))->failed());
    }

    // ──────────────────────────────────────────────────────────────
    // Transformable
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function to_array_unwraps_backed_enums_and_skips_nulls(): void
    {
        $request = FixtureUpdateThingRequest::from([
            FixtureUpdateThingRequest::name => 'Renamed',
            FixtureUpdateThingRequest::status => FixtureThingStatus::archived->value,
        ]);

        self::assertSame(['name' => 'Renamed', 'status' => 'archived'], $request->toArray());

        $partial = FixtureUpdateThingRequest::from([FixtureUpdateThingRequest::name => 'Only name']);

        self::assertSame(['name' => 'Only name'], $partial->toArray());
    }

    #[Test]
    public function to_array_recurses_into_nested_models_and_lists(): void
    {
        $response = FixtureThingsResponse::from([
            FixtureThingsResponse::things => [
                ['id' => '01H', 'name' => 'First', 'status' => 'active'],
            ],
            FixtureThingsResponse::Pagination => ['total' => 1],
        ]);

        self::assertSame([
            'things' => [
                ['id' => '01H', 'name' => 'First', 'status' => 'active'],
            ],
            'Pagination' => ['total' => 1],
        ], $response->toArray());
    }

    #[Test]
    public function to_json_encodes_the_array_form(): void
    {
        $request = FixtureCreateThingRequest::from([
            FixtureCreateThingRequest::name => 'Created',
            FixtureCreateThingRequest::status => FixtureThingStatus::active->value,
        ]);

        self::assertSame('{"name":"Created","status":"active"}', $request->toJson());
    }

    // ──────────────────────────────────────────────────────────────
    // AdminApi model-name helpers
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function short_name_reduces_a_model_reference_to_its_basename(): void
    {
        self::assertNull(AdminApi::shortName(null));
        self::assertSame('Errors', AdminApi::shortName('Errors'));
        self::assertSame('Errors', AdminApi::shortName(Errors::class));
        self::assertSame('FixtureThing', AdminApi::shortName(FixtureThing::class));
    }

    #[Test]
    public function default_fqcn_prefixes_the_package_models_namespace(): void
    {
        self::assertNull(AdminApi::defaultFqcn(null));
        self::assertSame(Errors::class, AdminApi::defaultFqcn('Errors'));
        self::assertSame(Errors::class, AdminApi::defaultFqcn(Errors::class));
        // Already qualified — returned untouched, whatever the namespace.
        self::assertSame(FixtureThing::class, AdminApi::defaultFqcn(FixtureThing::class));
    }

    // ──────────────────────────────────────────────────────────────
    // Fake transport
    // ──────────────────────────────────────────────────────────────

    #[Test]
    public function fake_returns_an_empty_200_when_the_queue_is_exhausted(): void
    {
        $fake = new Fake();

        $response = $fake->request('GET', '/v1/things');

        self::assertSame(200, $response->status);
        self::assertSame('', $response->body);
        self::assertSame([], $response->headers);
    }

    #[Test]
    public function fake_records_every_request_in_order(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');
        $api->deleteThing('01H');

        $fake->assertSentCount(2);
        self::assertSame(['GET', 'DELETE'], array_column($fake->recorded(), 'method'));
    }

    #[Test]
    public function assert_sent_matches_on_method_alone(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');

        $fake->assertSent('get');
        $fake->assertSent(HttpMethod::GET->value, '/v1/things/01H');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_sent_throws_when_nothing_matches_the_method(): void
    {
        $fake = new Fake();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No matching request [POST] was recorded.');

        $fake->assertSent('POST');
    }

    #[Test]
    public function assert_sent_throws_when_the_url_does_not_match(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('No matching request [GET] [/v1/gadgets] was recorded.');

        $fake->assertSent('GET', '/v1/gadgets');
    }

    #[Test]
    public function assert_not_sent_passes_when_no_request_matches(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');

        $fake->assertNotSent('POST');
        $fake->assertNotSent('GET', '/v1/gadgets');
        $this->expectNotToPerformAssertions();
    }

    #[Test]
    public function assert_not_sent_throws_when_a_matching_request_was_recorded(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected request [GET] [/v1/things/01H] was recorded.');

        $fake->assertNotSent('GET', '/v1/things/01H');
    }

    #[Test]
    public function assert_not_sent_throws_without_a_url_when_the_method_matches(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unexpected request [GET] was recorded.');

        $fake->assertNotSent('GET');
    }

    #[Test]
    public function assert_sent_count_throws_on_a_mismatch(): void
    {
        [$api, $fake] = $this->fake();

        $api->getThing('01H');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Expected 2 requests, but 1 were recorded.');

        $fake->assertSentCount(2);
    }

    #[Test]
    public function queue_returns_the_fake_for_chaining(): void
    {
        $fake = new Fake();

        self::assertSame($fake, $fake->queue(new Response(200, [], 'a'), new Response(201, [], 'b')));
        self::assertSame('a', $fake->request('GET', '/a')->body);
        self::assertSame('b', $fake->request('GET', '/b')->body);
    }
}
