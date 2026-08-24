<?php

declare(strict_types=1);

namespace Zerotoprod\Sdk\Generator;

use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * An OpenAPI 3.0/3.1 document held in memory, plus local `$ref` resolution.
 *
 * JSON is decoded natively; YAML needs a parser — `symfony/yaml` or `ext-yaml`,
 * whichever is present. Neither is a hard requirement of the package, so a YAML
 * document with no parser installed fails with an actionable message rather
 * than a half-hearted parse — see {@see self::parse()}.
 *
 * Only same-document pointers (`#/...`) resolve; an external or remote `$ref`
 * is an error rather than a silent `mixed`, because dropping one would change
 * a property's type without anybody noticing.
 *
 * @internal
 */
final class Document
{
    /**
     * Longest `$ref` -> `$ref` chain followed before giving up. Cycles are
     * caught by identity first; this is the backstop for a pathologically deep
     * but acyclic chain.
     */
    public const MAX_REF_DEPTH = 64;

    /** @param array<string, mixed> $data */
    private function __construct(private readonly array $data) {}

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self($data);
    }

    /**
     * Load from a filesystem path or an `http(s)` URL.
     *
     * `$reader` overrides the read for tests; it receives the source string and
     * returns the document body (or `false` to signal failure).
     *
     * @param (callable(string): (string|false))|null $reader
     */
    public static function load(string $source, ?callable $reader = null): self
    {
        $raw = $reader !== null ? $reader($source) : @file_get_contents($source);

        if (!is_string($raw)) {
            throw new GeneratorException("Cannot read OpenAPI document: $source");
        }

        return self::parse($raw, $source);
    }

    /**
     * Decode a document body, JSON or YAML. `$label` names the source in error
     * messages.
     *
     * The format is taken from the body, not the file extension: a leading `{`
     * means JSON, anything else is handed to a YAML parser. JSON is a subset of
     * YAML, so a `.yaml` file holding a flow mapping still decodes correctly.
     */
    public static function parse(string $raw, string $label): self
    {
        $head = ltrim($raw);

        if ($head === '') {
            throw new GeneratorException("OpenAPI document is empty: $label");
        }

        $data = $head[0] === '{' ? self::decodeJson($raw, $label) : self::decodeYaml($raw, $label);

        if (!is_array($data)) {
            throw new GeneratorException("OpenAPI document is not an object: $label");
        }

        /** @var array<string, mixed> $data */
        return new self($data);
    }

    private static function decodeJson(string $raw, string $label): mixed
    {
        $data = json_decode($raw, true);

        if ($data === null && json_last_error() !== JSON_ERROR_NONE) {
            throw new GeneratorException("Malformed JSON in $label: " . json_last_error_msg());
        }

        return $data;
    }

    /**
     * YAML needs a parser the package does not depend on: `symfony/yaml` first
     * (YAML 1.2-ish, leaves dates as strings), then `ext-yaml`.
     */
    private static function decodeYaml(string $raw, string $label): mixed
    {
        $raw = self::flattenComplexKeys(self::stripDocumentStart($raw));

        if (class_exists(Yaml::class)) {
            try {
                return Yaml::parse($raw);
            } catch (ParseException $exception) {
                throw new GeneratorException(
                    "Malformed YAML in $label: " . $exception->getMessage(),
                    0,
                    $exception,
                );
            }
        }

        if (function_exists('yaml_parse')) {
            $data = @yaml_parse($raw);

            if ($data === false) {
                throw new GeneratorException("Malformed YAML in $label");
            }

            return $data;
        }

        throw new GeneratorException(
            "$label looks like a YAML OpenAPI document, but no YAML parser is installed."
            . ' Install one with `composer require --dev symfony/yaml`, enable `ext-yaml`,'
            . ' or convert the document first, e.g. `npx -y js-yaml spec.yaml > spec.json`.',
        );
    }

    /**
     * Remove a leading document-start marker and the preamble in front of it.
     *
     * A single-document stream may still open with `---`, after a comment
     * header or a `%YAML` directive — GitLab's OpenAPI document does exactly
     * that. symfony/yaml only tolerates the marker on the very first line and
     * otherwise reads it as the start of a second document, refusing the file
     * with "Multiple documents are not supported". A marker further in really
     * is a multi-document stream, and still fails.
     */
    private static function stripDocumentStart(string $raw): string
    {
        $lines = preg_split('/\R/', $raw);

        if ($lines === false) {
            return $raw;
        }

        foreach ($lines as $index => $line) {
            $line = rtrim($line);

            if ($line === '' || $line[0] === '#' || $line[0] === '%') {
                continue;
            }

            // Content before any marker: nothing to strip.
            return $line === '---' ? implode("\n", array_slice($lines, $index + 1)) : $raw;
        }

        return $raw;
    }

    /**
     * Rewrite YAML complex mapping keys (`? key` / `: value`) as plain ones.
     *
     * A dumper emits the explicit form for any key past its length limit — Ruby's
     * Psych does it at 128 characters, which is why GitLab's OpenAPI document
     * writes its longest paths that way. symfony/yaml refuses the construct
     * outright ("Complex mappings are not supported"), and every one of these is
     * really a plain string key, so the two lines fold back into one:
     *
     *     ? "/very/long/path"        "/very/long/path":
     *     : get:              ->       get:
     *         summary: …                 summary: …
     *
     * The value keeps its original column, so the block underneath it still
     * lines up. Anything else — a multi-line or non-scalar key — is left alone
     * and still fails, rather than being mangled into something that parses.
     */
    private static function flattenComplexKeys(string $raw): string
    {
        $lines = preg_split('/\R/', $raw);

        if ($lines === false) {
            return $raw;
        }

        $out = [];

        for ($index = 0, $total = count($lines); $index < $total; $index++) {
            $key = [];
            $next = $lines[$index + 1] ?? '';

            // `? |` and friends open a multi-line key, which this cannot fold.
            if (preg_match('/^(\s*)\? ([^|>].*)$/', $lines[$index], $key) !== 1) {
                $out[] = $lines[$index];

                continue;
            }

            $indent = preg_quote($key[1], '/');
            $value = [];

            if (preg_match("/^$indent:(?: (\S.*))?$/", $next, $value) !== 1) {
                $out[] = $lines[$index];

                continue;
            }

            $out[] = $key[1] . rtrim($key[2]) . ':';

            if (($value[1] ?? '') !== '') {
                $out[] = $key[1] . '  ' . $value[1];
            }

            $index++;
        }

        return implode("\n", $out);
    }

    /** The `openapi` version string, or `0.0.0` when absent. */
    public function version(): string
    {
        return Json::str($this->data['openapi'] ?? null) ?? '0.0.0';
    }

    /** @return array<string, mixed> */
    public function schemas(): array
    {
        return Json::map(Json::map($this->data['components'] ?? null)['schemas'] ?? null);
    }

    /** @return array<string, mixed> */
    public function paths(): array
    {
        return Json::map($this->data['paths'] ?? null);
    }

    /**
     * Webhook definitions under either the 3.1 `webhooks` key or the 3.0
     * `x-webhooks` extension GitHub uses.
     *
     * @return array<string, mixed>
     */
    public function webhooks(): array
    {
        $webhooks = Json::map($this->data['webhooks'] ?? null);

        return $webhooks !== [] ? $webhooks : Json::map($this->data['x-webhooks'] ?? null);
    }

    public function hasSchema(string $name): bool
    {
        return array_key_exists($name, $this->schemas());
    }

    /**
     * Follow a chain of `$ref`s until a concrete node is reached. A node with
     * no `$ref` is returned untouched, so this is safe to call on anything.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    public function resolve(array $node, int $maxDepth = self::MAX_REF_DEPTH): array
    {
        /** @var array<string, true> $seen */
        $seen = [];
        $depth = 0;

        while (($ref = Json::str($node['$ref'] ?? null)) !== null) {
            if (isset($seen[$ref])) {
                throw new GeneratorException(
                    'Circular $ref chain: ' . implode(' -> ', array_keys($seen)) . " -> $ref",
                );
            }

            if (++$depth > $maxDepth) {
                throw new GeneratorException("\$ref chain deeper than $maxDepth levels at $ref");
            }

            $seen[$ref] = true;
            $node = $this->pointer($ref);
        }

        return $node;
    }

    /**
     * Dereference one JSON pointer against this document.
     *
     * @return array<string, mixed>
     */
    public function pointer(string $ref): array
    {
        if (!str_starts_with($ref, '#/')) {
            throw new GeneratorException(
                "Only local \$ref pointers are supported (must start with '#/'), got: $ref",
            );
        }

        $node = $this->data;

        foreach (explode('/', substr($ref, 2)) as $segment) {
            $key = str_replace(['~1', '~0'], ['/', '~'], rawurldecode($segment));

            if (!is_array($node) || !array_key_exists($key, $node)) {
                throw new GeneratorException("Unresolvable \$ref: $ref");
            }

            $node = $node[$key];
        }

        if (!is_array($node)) {
            throw new GeneratorException("\$ref does not point at an object: $ref");
        }

        /** @var array<string, mixed> $node */
        return $node;
    }

    /**
     * The last segment of a pointer — the component name.
     * `#/components/schemas/simple-user` -> `simple-user`.
     */
    public static function refName(string $ref): string
    {
        $position = strrpos($ref, '/');

        return $position === false ? $ref : substr($ref, $position + 1);
    }
}
