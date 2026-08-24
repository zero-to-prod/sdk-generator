<?php

namespace Unit\Generator;

use PHPUnit\Framework\Attributes\Test;
use Tests\Unit\Generator\GeneratorCase;
use Zerotoprod\Sdk\Generator\Document;
use Zerotoprod\Sdk\Generator\GeneratorException;

class DocumentTest extends GeneratorCase
{
    #[Test]
    public function it_loads_a_json_file_from_disk(): void
    {
        $document = self::document('widgets');

        self::assertSame('3.0.3', $document->version());
        self::assertArrayHasKey('widget', $document->schemas());
        self::assertArrayHasKey('/v1/widgets', $document->paths());
    }

    #[Test]
    public function a_reader_closure_replaces_the_fetch_so_urls_need_no_network(): void
    {
        $seen = '';
        $document = Document::load(
            'https://example.com/openapi.json',
            function (string $source) use (&$seen): string {
                $seen = $source;

                return '{"openapi": "3.1.0"}';
            },
        );

        self::assertSame('https://example.com/openapi.json', $seen);
        self::assertSame('3.1.0', $document->version());
    }

    #[Test]
    public function an_unreadable_source_names_itself_in_the_error(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Cannot read OpenAPI document: /no/such/spec.json');

        Document::load('/no/such/spec.json');
    }

    #[Test]
    public function a_reader_returning_false_is_a_read_failure(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Cannot read OpenAPI document');

        Document::load('spec.json', static fn(): bool => false);
    }

    #[Test]
    public function an_empty_document_is_rejected(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('OpenAPI document is empty');

        Document::load(self::fixture('empty.json'));
    }

    #[Test]
    public function it_loads_a_yaml_file_from_disk(): void
    {
        $document = Document::load(self::fixture('yaml.yaml'));

        self::assertSame('3.0.3', $document->version());
        self::assertArrayHasKey('thing', $document->schemas());
        self::assertArrayHasKey('/v1/things', $document->paths());
    }

    #[Test]
    public function yaml_anchors_and_merge_keys_are_resolved_by_the_parser(): void
    {
        $thing = Document::load(self::fixture('yaml.yaml'))->schemas()['thing'];

        self::assertSame('object', $thing['type']);
        self::assertArrayHasKey('name', $thing['properties']);
    }

    #[Test]
    public function malformed_yaml_reports_the_decode_error(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Malformed YAML in inline');

        Document::parse("openapi: 3.0.3\ninfo:\n  title: a\n bad: indent\n", 'inline');
    }

    #[Test]
    public function malformed_json_reports_the_decode_error(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Malformed JSON in');

        Document::load(self::fixture('malformed.json'));
    }

    #[Test]
    public function a_scalar_body_is_not_a_document(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('OpenAPI document is not an object: inline');

        Document::parse('"just a string"', 'inline');
    }

    #[Test]
    public function version_defaults_when_the_key_is_absent(): void
    {
        self::assertSame('0.0.0', Document::fromArray([])->version());
    }

    #[Test]
    public function schemas_paths_and_webhooks_default_to_empty(): void
    {
        $document = Document::fromArray([]);

        self::assertSame([], $document->schemas());
        self::assertSame([], $document->paths());
        self::assertSame([], $document->webhooks());
        self::assertFalse($document->hasSchema('anything'));
    }

    #[Test]
    public function webhooks_prefers_the_31_key_over_the_extension(): void
    {
        $document = Document::fromArray([
            'webhooks' => ['modern' => []],
            'x-webhooks' => ['legacy' => []],
        ]);

        self::assertSame(['modern' => []], $document->webhooks());
    }

    #[Test]
    public function webhooks_falls_back_to_the_30_extension(): void
    {
        self::assertSame(
            ['legacy' => []],
            Document::fromArray(['x-webhooks' => ['legacy' => []]])->webhooks(),
        );
    }

    #[Test]
    public function resolve_returns_a_node_without_a_ref_untouched(): void
    {
        $node = ['type' => 'string'];

        self::assertSame($node, Document::fromArray([])->resolve($node));
    }

    #[Test]
    public function resolve_follows_a_chain_of_refs(): void
    {
        $resolved = self::document('cycles')->resolve(['$ref' => '#/components/schemas/hop-a']);

        self::assertArrayHasKey('properties', $resolved);
        self::assertArrayHasKey('parent', $resolved['properties']);
    }

    #[Test]
    public function resolve_detects_a_ref_cycle_instead_of_hanging(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Circular $ref chain');

        self::document('ref-cycle')->resolve(['$ref' => '#/components/schemas/loop-a']);
    }

    #[Test]
    public function resolve_enforces_a_depth_limit_on_a_long_acyclic_chain(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('$ref chain deeper than 1 levels');

        self::document('cycles')->resolve(['$ref' => '#/components/schemas/hop-a'], 1);
    }

    #[Test]
    public function the_default_depth_limit_is_generous(): void
    {
        self::assertSame(64, Document::MAX_REF_DEPTH);
    }

    #[Test]
    public function pointer_reads_a_nested_location(): void
    {
        $parameter = self::document('widgets')->pointer('#/components/parameters/page');

        self::assertSame('page', $parameter['name']);
        self::assertSame('query', $parameter['in']);
    }

    #[Test]
    public function pointer_unescapes_tilde_and_percent_sequences(): void
    {
        $document = Document::fromArray(['a/b' => ['c~d' => ['e f' => ['ok' => true]]]]);

        self::assertSame(['ok' => true], $document->pointer('#/a~1b/c~0d/e%20f'));
    }

    #[Test]
    public function a_remote_ref_is_refused(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Only local $ref pointers are supported');

        Document::fromArray([])->pointer('https://example.com/other.json#/Widget');
    }

    #[Test]
    public function a_missing_pointer_target_is_an_error(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Unresolvable $ref: #/components/schemas/nope');

        self::document('widgets')->pointer('#/components/schemas/nope');
    }

    #[Test]
    public function a_pointer_through_a_scalar_is_an_error(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('Unresolvable $ref');

        Document::fromArray(['a' => 'scalar'])->pointer('#/a/b');
    }

    #[Test]
    public function a_pointer_at_a_scalar_is_an_error(): void
    {
        $this->expectException(GeneratorException::class);
        $this->expectExceptionMessage('$ref does not point at an object');

        Document::fromArray(['a' => 'scalar'])->pointer('#/a');
    }

    #[Test]
    public function ref_name_is_the_last_pointer_segment(): void
    {
        self::assertSame('simple-user', Document::refName('#/components/schemas/simple-user'));
        self::assertSame('bare', Document::refName('bare'));
    }
}
