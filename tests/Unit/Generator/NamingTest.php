<?php

namespace Unit\Generator;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;
use Zerotoprod\Sdk\Generator\Naming;

class NamingTest extends TestCase
{
    // ─── Class names ───────────────────────────────────────────────────

    #[Test]
    public function it_pascal_cases_a_kebab_schema_name(): void
    {
        self::assertSame('SimpleUser', (new Naming())->className('simple-user'));
    }

    #[Test]
    public function it_keeps_numeric_suffixes_on_snake_case_webhook_names(): void
    {
        self::assertSame('WebhooksIssues2', (new Naming())->className('webhooks_issues_2'));
    }

    #[Test]
    public function it_preserves_long_names_rather_than_truncating(): void
    {
        $raw = 'nullable-secret-scanning-first-detected-location-with-extra-words';
        $class = (new Naming())->className($raw);

        self::assertSame('NullableSecretScanningFirstDetectedLocationWithExtraWords', $class);
        self::assertGreaterThan(55, strlen($class));
    }

    #[Test]
    public function a_reserved_word_gains_a_model_suffix(): void
    {
        $naming = new Naming();

        self::assertSame('ClassModel', $naming->className('class'));
        self::assertSame('ListModel', $naming->className('list'));
        self::assertSame('StringModel', $naming->className('string'));
    }

    #[Test]
    public function a_leading_digit_is_prefixed_so_the_class_name_is_valid(): void
    {
        self::assertSame('_2faStatus', (new Naming())->className('2fa-status'));
    }

    #[Test]
    public function a_nameless_schema_still_gets_a_class(): void
    {
        self::assertSame('Schema', (new Naming())->className('---'));
    }

    #[Test]
    public function genuine_collisions_get_a_numeric_discriminator(): void
    {
        $naming = new Naming();

        self::assertSame('FooBar', $naming->className('foo-bar'));
        self::assertSame('FooBar2', $naming->className('foo_bar'));
        self::assertSame('FooBar3', $naming->className('FooBar'));
    }

    #[Test]
    public function asking_twice_for_one_schema_returns_the_same_class(): void
    {
        $naming = new Naming();

        self::assertSame('SimpleUser', $naming->className('simple-user'));
        self::assertSame('SimpleUser', $naming->className('simple-user'));
    }

    #[Test]
    public function class_taken_reports_the_registry(): void
    {
        $naming = new Naming();

        self::assertFalse($naming->classTaken('SimpleUser'));
        $naming->className('simple-user');
        self::assertTrue($naming->classTaken('SimpleUser'));
    }

    // ─── The CLAUDE.md operation table ─────────────────────────────────

    /** @return list<array{string, string, string}> */
    public static function methodTable(): array
    {
        return [
            ['GET', '/v1/widgets/{id}', 'getWidget'],
            ['GET', '/v1/widgets', 'listWidgets'],
            ['POST', '/v1/widgets', 'createWidget'],
            ['PATCH', '/v1/widgets/{id}', 'updateWidget'],
            ['PUT', '/v1/widgets/{id}', 'updateWidget'],
            ['DELETE', '/v1/widgets/{id}', 'deleteWidget'],
            ['POST', '/v1/accounts/{id}/mfa-enrollments', 'createAccountMfaEnrollment'],
            ['GET', '/v1/accounts/{id}/mfa-enrollments', 'listAccountMfaEnrollments'],
            ['DELETE', '/v1/accounts/{id}/mfa-enrollments/{mfa_id}', 'deleteAccountMfaEnrollment'],
            ['GET', '/v1/accounts/{id}/providers', 'listAccountProviders'],
            ['GET', '/repos/{owner}/{repo}/statuses/{sha}', 'getRepoStatus'],
            ['GET', '/repos/{owner}/{repo}/statuses', 'listRepoStatuses'],
            ['GET', '/', 'listRoot'],
            ['GET', '/v1/{id}', 'getRoot'],
        ];
    }

    #[Test]
    #[DataProvider('methodTable')]
    public function it_maps_an_operation_to_a_method_name(string $verb, string $path, string $expected): void
    {
        self::assertSame($expected, (new Naming())->methodName($verb, $path));
    }

    #[Test]
    public function a_version_segment_is_dropped_from_the_resource_words(): void
    {
        $naming = new Naming();

        self::assertSame('listWidgets', $naming->methodName('GET', '/v1/widgets'));
        self::assertSame('listWidgets', (new Naming())->methodName('GET', '/V2/widgets'));
        self::assertSame('listWidgets', (new Naming())->methodName('GET', '/widgets'));
    }

    #[Test]
    public function put_keeps_update_and_patch_on_the_same_path_becomes_patch(): void
    {
        $naming = new Naming();

        // Visit order matters, and RouteMapper::VERBS puts PUT first.
        self::assertSame('updateWidget', $naming->methodName('PUT', '/v1/widgets/{id}'));
        self::assertSame('patchWidget', $naming->methodName('PATCH', '/v1/widgets/{id}'));
    }

    #[Test]
    public function patch_alone_still_gets_update(): void
    {
        self::assertSame('updateWidget', (new Naming())->methodName('PATCH', '/v1/widgets/{id}'));
    }

    #[Test]
    public function put_and_patch_on_different_paths_both_get_update(): void
    {
        $naming = new Naming();

        self::assertSame('updateWidget', $naming->methodName('PUT', '/v1/widgets/{id}'));
        self::assertSame('updateGadget', $naming->methodName('PATCH', '/v1/gadgets/{id}'));
    }

    #[Test]
    public function an_exhausted_candidate_list_falls_back_to_a_discriminator(): void
    {
        $naming = new Naming();

        self::assertSame('updateWidget', $naming->methodName('PUT', '/v1/widgets/{id}'));
        self::assertSame('patchWidget', $naming->methodName('PATCH', '/v1/widgets/{id}'));
        // A third path whose words collide has nothing left to try.
        self::assertSame('patchWidget2', $naming->methodName('PATCH', '/v2/widgets/{other}'));
    }

    #[Test]
    public function asking_twice_for_one_operation_returns_the_same_method(): void
    {
        $naming = new Naming();

        self::assertSame('getWidget', $naming->methodName('GET', '/v1/widgets/{id}'));
        self::assertSame('getWidget', $naming->methodName('get', '/v1/widgets/{id}'));
    }

    // ─── Route case names ──────────────────────────────────────────────

    /** @return list<array{string, string}> */
    public static function routeCases(): array
    {
        return [
            ['/v1/widgets', 'widgets'],
            ['/v1/widgets/{id}', 'widget'],
            ['/v1/accounts/{id}/mfa-enrollments', 'account_mfa_enrollments'],
            ['/repos/{owner}/{repo}/git/blobs/{file_sha}', 'repo_git_blob'],
            ['/', 'root'],
            ['/v1/mfaEnrollments', 'mfa_enrollments'],
            ['/v1/2fa', '_2fa'],
            ['/v1/class', 'class_'],
        ];
    }

    #[Test]
    #[DataProvider('routeCases')]
    public function it_maps_a_path_to_a_route_case_name(string $path, string $expected): void
    {
        self::assertSame($expected, (new Naming())->routeCaseName($path));
    }

    #[Test]
    public function route_case_collisions_get_a_discriminator(): void
    {
        $naming = new Naming();

        self::assertSame('widgets', $naming->routeCaseName('/v1/widgets'));
        self::assertSame('widgets2', $naming->routeCaseName('/v2/widgets'));
    }

    #[Test]
    public function asking_twice_for_one_path_returns_the_same_case(): void
    {
        $naming = new Naming();

        self::assertSame('widgets', $naming->routeCaseName('/v1/widgets'));
        self::assertSame('widgets', $naming->routeCaseName('/v1/widgets'));
    }

    // ─── Enum case sanitisation ────────────────────────────────────────

    /**
     * Every enum value in the GitHub REST description that is not already a
     * valid PHP identifier.
     *
     * @return list<array{string, string}>
     */
    public static function enumValues(): array
    {
        return [
            ['*', 'asterisk'],
            ['/', 'slash'],
            ['-1', 'minus_1'],
            ['+1', 'plus_1'],
            ['/docs', 'docs'],
            ['040000', '_040000'],
            ['100644', '_100644'],
            ['100755', '_100755'],
            ['120000', '_120000'],
            ['160000', '_160000'],
            ['2fa_disabled', '_2fa_disabled'],
            ['2fa_insecure', '_2fa_insecure'],
            ['author-date', 'author_date'],
            ['c-cpp', 'c_cpp'],
            ['committer-date', 'committer_date'],
            ['critical-resource', 'critical_resource'],
            ['custom-pattern-backfill', 'custom_pattern_backfill'],
            ['deleted ruleset', 'deleted_ruleset'],
            ['false positive', 'false_positive'],
            ['fast-forward', 'fast_forward'],
            ['gh-pages', 'gh_pages'],
            ['help-wanted-issues', 'help_wanted_issues'],
            ['internet-exposed', 'internet_exposed'],
            ['java-kotlin', 'java_kotlin'],
            ['javascript-typescript', 'javascript_typescript'],
            ['lateral-movement', 'lateral_movement'],
            ['long-running', 'long_running'],
            ['master /docs', 'master_docs'],
            ['not-configured', 'not_configured'],
            ['not-set', 'not_set'],
            ['off-topic', 'off_topic'],
            ['pattern-version-backfill', 'pattern_version_backfill'],
            ['pull-requests', 'pull_requests'],
            ['reactions-+1', 'reactions_plus_1'],
            ['reactions--1', 'reactions_minus_1'],
            ['reactions-heart', 'reactions_heart'],
            ['reactions-smile', 'reactions_smile'],
            ['reactions-tada', 'reactions_tada'],
            ['reactions-thinking_face', 'reactions_thinking_face'],
            ['read-only', 'read_only'],
            ['sensitive-data', 'sensitive_data'],
            ['too heated', 'too_heated'],
            ['used in tests', 'used_in_tests'],
            ["won't fix", 'won_t_fix'],
            ['', 'empty'],
            ['class', 'class_'],
            ['default', 'default'],
            ['1', '_1'],
        ];
    }

    #[Test]
    #[DataProvider('enumValues')]
    public function it_sanitises_an_enum_value_into_an_identifier(string $value, string $expected): void
    {
        self::assertSame($expected, Naming::enumCaseName($value));
    }

    #[Test]
    public function every_sanitised_github_enum_value_is_a_valid_identifier(): void
    {
        foreach (self::enumValues() as [$value, $expected]) {
            self::assertMatchesRegularExpression(
                '/^[A-Za-z_][A-Za-z0-9_]*$/',
                Naming::enumCaseName($value),
                "value " . var_export($value, true) . " did not sanitise to an identifier",
            );
        }
    }

    #[Test]
    public function the_sanitised_github_enum_values_do_not_collide(): void
    {
        $names = array_map(
            static fn(array $row): string => Naming::enumCaseName($row[0]),
            self::enumValues(),
        );

        self::assertSame(count($names), count(array_unique($names)), 'two enum values collapsed to one case name');
    }

    #[Test]
    public function signed_numbers_stay_distinct_from_each_other(): void
    {
        self::assertNotSame(Naming::enumCaseName('-1'), Naming::enumCaseName('+1'));
        self::assertNotSame(Naming::enumCaseName('reactions--1'), Naming::enumCaseName('reactions-+1'));
    }

    #[Test]
    public function spell_names_punctuation_and_ignores_the_rest(): void
    {
        self::assertSame('asterisk', Naming::spell('*'));
        self::assertSame('minus_gt', Naming::spell('->'));
        self::assertSame('', Naming::spell('abc'));
    }

    #[Test]
    public function a_value_of_only_unmapped_punctuation_falls_back_to_empty(): void
    {
        self::assertSame('empty', Naming::enumCaseName("\x01"));
    }

    // ─── Property names ────────────────────────────────────────────────

    /** @return list<array{string, string}> */
    public static function propertyNames(): array
    {
        return [
            ['created_at', 'created_at'],
            ['id', 'id'],
            ['2fa', '_2fa'],
            ['$ref', 'ref'],
            ['foo-bar', 'foo_bar'],
            ['+1', 'plus_1'],
            ['-1', 'minus_1'],
            ['this', 'this_'],
            ['class', 'class_'],
            ['', 'empty'],
        ];
    }

    #[Test]
    #[DataProvider('propertyNames')]
    public function it_maps_a_wire_key_to_a_property_name(string $wire, string $expected): void
    {
        self::assertSame($expected, Naming::propertyName($wire));
    }

    // ─── String shaping ────────────────────────────────────────────────

    #[Test]
    public function pascal_and_camel_agree_apart_from_the_first_letter(): void
    {
        self::assertSame('MfaEnrollment', Naming::pascal('mfa-enrollment'));
        self::assertSame('mfaEnrollment', Naming::camel('mfa-enrollment'));
        self::assertSame('', Naming::pascal(''));
    }

    #[Test]
    public function snake_splits_camel_humps_as_well_as_punctuation(): void
    {
        self::assertSame('mfa_enrollments', Naming::snake('mfaEnrollments'));
        self::assertSame('mfa_enrollments', Naming::snake('mfa-enrollments'));
        self::assertSame('', Naming::snake('---'));
    }

    // ─── Pluralisation ─────────────────────────────────────────────────

    /** @return list<array{string, string}> */
    public static function words(): array
    {
        return [
            ['widgets', 'widget'],
            ['enterprises', 'enterprise'],
            ['statuses', 'status'],
            ['status', 'status'],
            ['addresses', 'address'],
            ['boxes', 'box'],
            ['branches', 'branch'],
            ['dishes', 'dish'],
            ['prizes', 'prize'],
            ['quizzes', 'quizz'],
            ['categories', 'category'],
            ['people', 'person'],
            ['children', 'child'],
            ['data', 'data'],
            ['series', 'series'],
            ['class', 'class'],
            ['widget', 'widget'],
        ];
    }

    #[Test]
    #[DataProvider('words')]
    public function it_singularises(string $plural, string $singular): void
    {
        self::assertSame($singular, Naming::singular($plural));
    }

    #[Test]
    #[DataProvider('words')]
    public function pluralising_a_singular_round_trips(string $plural, string $singular): void
    {
        self::assertSame(
            Naming::plural($singular),
            Naming::plural(Naming::singular($plural)),
            'singular/plural do not round-trip',
        );
    }

    #[Test]
    public function it_pluralises(): void
    {
        self::assertSame('widgets', Naming::plural('widget'));
        self::assertSame('categories', Naming::plural('category'));
        self::assertSame('boxes', Naming::plural('box'));
        self::assertSame('statuses', Naming::plural('status'));
        self::assertSame('people', Naming::plural('person'));
        self::assertSame('data', Naming::plural('data'));
        self::assertSame('days', Naming::plural('day'));
    }

    // ─── Paths ─────────────────────────────────────────────────────────

    #[Test]
    public function path_parameters_come_back_in_path_order_and_verbatim(): void
    {
        self::assertSame(
            ['owner', 'repo', 'file_sha'],
            Naming::pathParameters('/repos/{owner}/{repo}/git/blobs/{file_sha}'),
        );
    }

    #[Test]
    public function a_hyphenated_path_parameter_is_not_rewritten(): void
    {
        // It goes into a str_replace of `{enterprise-team}`, not an identifier.
        self::assertSame(['enterprise-team'], Naming::pathParameters('/enterprises/{enterprise-team}'));
    }

    #[Test]
    public function a_path_with_no_parameters_yields_none(): void
    {
        self::assertSame([], Naming::pathParameters('/v1/widgets'));
    }

    #[Test]
    public function resource_segments_drop_the_version_and_the_placeholders(): void
    {
        self::assertSame(['accounts', 'providers'], Naming::resourceSegments('/v1/accounts/{id}/providers'));
        self::assertSame([], Naming::resourceSegments('/'));
        self::assertSame([], Naming::resourceSegments('/v1/{id}'));
    }

    #[Test]
    public function ends_with_parameter_distinguishes_an_item_from_a_collection(): void
    {
        self::assertTrue(Naming::endsWithParameter('/v1/widgets/{id}'));
        self::assertTrue(Naming::endsWithParameter('/v1/widgets/{id}/'));
        self::assertFalse(Naming::endsWithParameter('/v1/widgets'));
        self::assertFalse(Naming::endsWithParameter('/v1/widgets/{id}/tags'));
    }

    #[Test]
    public function a_reserved_class_name_is_not_handed_to_a_document_schema(): void
    {
        $naming = new Naming();
        $naming->reserveClasses(['Errors', 'Pagination', 'Query']);

        self::assertTrue($naming->classTaken('Errors'));
        self::assertSame('Query2', $naming->className('query'));
        self::assertSame('Errors2', $naming->className('errors'));
        // Asking twice still answers the same thing.
        self::assertSame('Query2', $naming->className('query'));
        // A name nobody reserved is untouched.
        self::assertSame('Widget', $naming->className('widget'));
    }
}
