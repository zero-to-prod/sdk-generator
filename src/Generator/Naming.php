<?php

declare(strict_types=1);

namespace Zerotoprod\Sdk\Generator;

/**
 * Every name the generator invents, in one place.
 *
 * Three guarantees hold for all of it:
 *
 *  - **Total** — any input string yields a valid PHP identifier. No input is
 *    rejected, so a hostile schema name cannot abort a run.
 *  - **Deterministic** — the same document always produces the same names, so
 *    regenerating is a no-op diff.
 *  - **Collision-free** — an instance keeps a registry per name kind and
 *    appends a numeric discriminator when two different inputs would otherwise
 *    land on the same identifier. Asking twice for the same input returns the
 *    same answer.
 *
 * @internal
 */
final class Naming
{
    /**
     * Lowercased words PHP will not accept as a class name. Enum *case* names
     * are class constants and may be keywords, so only `class` matters there
     * — see {@see self::enumCaseName()}.
     *
     * @var list<string>
     */
    public const RESERVED = [
        'abstract', 'and', 'array', 'as', 'break', 'callable', 'case', 'catch', 'class', 'clone',
        'const', 'continue', 'declare', 'default', 'die', 'do', 'echo', 'else', 'elseif', 'empty',
        'enddeclare', 'endfor', 'endforeach', 'endif', 'endswitch', 'endwhile', 'enum', 'eval',
        'exit', 'extends', 'final', 'finally', 'fn', 'for', 'foreach', 'function', 'global',
        'goto', 'if', 'implements', 'include', 'include_once', 'instanceof', 'insteadof',
        'interface', 'isset', 'list', 'match', 'namespace', 'new', 'or', 'print', 'private',
        'protected', 'public', 'readonly', 'require', 'require_once', 'return', 'static', 'switch',
        'throw', 'trait', 'try', 'unset', 'use', 'var', 'while', 'xor', 'yield',
        'bool', 'false', 'float', 'int', 'iterable', 'mixed', 'never', 'null', 'object',
        'parent', 'self', 'string', 'true', 'void',
    ];

    /** Words whose plural equals their singular. @var list<string> */
    public const UNCOUNTABLE = ['data', 'info', 'media', 'metadata', 'news', 'series'];

    /** Singular => plural for words the suffix rules get wrong. @var array<string, string> */
    public const IRREGULAR = [
        'child' => 'children',
        'person' => 'people',
        'status' => 'statuses',
    ];

    /**
     * Spelled-out names for punctuation, used when a value is *nothing but*
     * punctuation and would otherwise sanitize to an empty identifier. Keeps
     * `*` and `/` — both legal GitHub enum values — from colliding.
     *
     * @var array<string, string>
     */
    public const SYMBOLS = [
        '*' => 'asterisk', '/' => 'slash', '\\' => 'backslash', '+' => 'plus', '-' => 'minus',
        '.' => 'dot', ',' => 'comma', ':' => 'colon', ';' => 'semicolon', '?' => 'question',
        '!' => 'bang', '@' => 'at', '#' => 'hash', '$' => 'dollar', '%' => 'percent',
        '^' => 'caret', '&' => 'and', '=' => 'equals', '<' => 'lt', '>' => 'gt', '|' => 'pipe',
        '~' => 'tilde', '(' => 'lparen', ')' => 'rparen', '[' => 'lbracket', ']' => 'rbracket',
        '{' => 'lbrace', '}' => 'rbrace', '"' => 'quote', "'" => 'apostrophe', '`' => 'backtick',
        ' ' => 'space',
    ];

    /** @var array<string, string> raw schema name => class name */
    private array $classes = [];

    /** @var array<string, true> */
    private array $classesTaken = [];

    /** @var array<string, string> "VERB /path" => method name */
    private array $methods = [];

    /** @var array<string, true> */
    private array $methodsTaken = [];

    /** @var array<string, string> path => route case name */
    private array $routes = [];

    /** @var array<string, true> */
    private array $routesTaken = [];

    // ─── Class names ───────────────────────────────────────────────────

    /**
     * Class name for a schema name. `simple-user` -> `SimpleUser`,
     * `webhooks_issues_2` -> `WebhooksIssues2`.
     */
    public function className(string $raw): string
    {
        if (isset($this->classes[$raw])) {
            return $this->classes[$raw];
        }

        $base = self::pascal($raw);

        if ($base === '') {
            $base = 'Schema';
        }

        if (in_array(strtolower($base), self::RESERVED, true)) {
            $base .= 'Model';
        }

        return $this->classes[$raw] = $this->claim($base, $this->classesTaken);
    }

    /**
     * Reserve class names the document is not allowed to claim.
     *
     * The hand-written models in `retain_models` are the package's own, not the
     * document's: `Errors` and `Query` are resolved by the shared client code
     * in `src/`. The sweep already refuses to delete them, but a document
     * schema landing on one of their names would overwrite the file and break
     * `src/` rather than merely shadowing an example. Reserving the names up
     * front sends the document's schema to `Errors2`/`Query2` instead.
     *
     * @param list<string> $classes
     */
    public function reserveClasses(array $classes): void
    {
        foreach ($classes as $class) {
            $this->classesTaken[$class] = true;
        }
    }

    /** Whether a class name has already been handed out. */
    public function classTaken(string $class): bool
    {
        return isset($this->classesTaken[$class]);
    }

    // ─── API method names ──────────────────────────────────────────────

    /**
     * API method name for an operation, per the naming convention in CLAUDE.md:
     *
     *     GET    /widgets/{id}  -> getWidget
     *     GET    /widgets       -> listWidgets
     *     POST   /widgets       -> createWidget
     *     PUT    /widgets/{id}  -> updateWidget
     *     PATCH  /widgets/{id}  -> updateWidget, or patchWidget when a PUT on
     *                              the same path already claimed `update`
     *     DELETE /widgets/{id}  -> deleteWidget
     *
     * Parent segments are folded into the name for sub-resources, so
     * `POST /accounts/{id}/mfa-enrollments` is `createAccountMfaEnrollment`.
     *
     * A leading version segment (`/v1`) and every `{placeholder}` segment are
     * dropped before the resource words are assembled.
     */
    public function methodName(string $httpMethod, string $path): string
    {
        $verb = strtoupper($httpMethod);
        $key = "$verb $path";

        if (isset($this->methods[$key])) {
            return $this->methods[$key];
        }

        $segments = self::resourceSegments($path);
        $last = array_pop($segments);
        $parents = implode('', array_map(
            static fn(string $segment): string => self::pascal(self::singular($segment)),
            $segments,
        ));

        $candidates = array_map(
            static function (string $prefix) use ($last, $parents): string {
                $resource = $last === null
                    ? 'Root'
                    : self::pascal($prefix === 'list' ? self::plural(self::singular($last)) : self::singular($last));

                return $prefix . $parents . $resource;
            },
            $this->verbs($verb, self::endsWithParameter($path)),
        );

        return $this->methods[$key] = $this->claimFirst($candidates, $this->methodsTaken);
    }

    /**
     * Verb prefixes to try, in order. More than one means the later entries are
     * fallbacks used only when an earlier name is already claimed.
     *
     * @return non-empty-list<string>
     */
    private function verbs(string $verb, bool $isItem): array
    {
        return match ($verb) {
            'GET' => $isItem ? ['get'] : ['list'],
            'POST' => ['create'],
            'PUT' => ['update'],
            'PATCH' => ['update', 'patch'],
            default => ['delete'],
        };
    }

    // ─── Route case names ──────────────────────────────────────────────

    /**
     * `ApiRoute` case name for a path: snake_case resource words, singular when
     * the path addresses a single item.
     *
     *     /v1/widgets            -> widgets
     *     /v1/widgets/{id}       -> widget
     *     /accounts/{id}/tokens  -> account_tokens
     */
    public function routeCaseName(string $path): string
    {
        if (isset($this->routes[$path])) {
            return $this->routes[$path];
        }

        $segments = self::resourceSegments($path);
        $last = array_pop($segments);

        $words = array_map(self::singular(...), $segments);

        if ($last !== null) {
            $words[] = self::endsWithParameter($path) ? self::singular($last) : $last;
        }

        $base = self::snake(implode('_', $words));

        if ($base === '') {
            $base = 'root';
        }

        if (preg_match('/^\d/', $base) === 1) {
            $base = "_$base";
        }

        if ($base === 'class') {
            $base = 'class_';
        }

        return $this->routes[$path] = $this->claim($base, $this->routesTaken);
    }

    // ─── Member names ──────────────────────────────────────────────────

    /**
     * PHP property name for a wire key. A key that is already a valid
     * identifier passes through untouched, so `created_at` stays `created_at`
     * and only oddities like `2fa`, `$ref` or `+1` are rewritten. The wire key
     * is preserved on the companion constant, and the property carries
     * `'from' => self::<const>` whenever the two differ.
     */
    public static function propertyName(string $wire): string
    {
        $name = self::enumCaseName($wire);

        // `$this` is the only property name PHP rejects outright; `class` is
        // already handled because the companion `public const class` is illegal.
        return strtolower($name) === 'this' ? "{$name}_" : $name;
    }

    /**
     * Enum case name for a backing value. The raw value is preserved as the
     * case's backing value, so this only has to be a stable identifier.
     *
     *     in-progress   -> in_progress
     *     +1 / -1       -> plus_1 / minus_1
     *     reactions--1  -> reactions_minus_1
     *     2fa_disabled  -> _2fa_disabled
     *     040000        -> _040000
     *     won't fix     -> won_t_fix
     *     *  /  /       -> asterisk / slash
     *     ""            -> empty
     */
    public static function enumCaseName(string $value): string
    {
        $name = '';
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $character = $value[$i];

            if (preg_match('/[A-Za-z0-9_]/', $character) === 1) {
                $name .= $character;
                continue;
            }

            $previous = $i > 0 ? $value[$i - 1] : '';
            $next = $i + 1 < $length ? $value[$i + 1] : '';

            // A leading sign in front of a number is meaningful: `-1` and `+1`
            // must not both collapse to `_1`.
            $isSign = ($character === '-' || $character === '+')
                && preg_match('/\d/', $next) === 1
                && preg_match('/[A-Za-z0-9]/', $previous) !== 1;

            $name .= $isSign ? '_' . self::SYMBOLS[$character] . '_' : '_';
        }

        $name = trim((string) preg_replace('/_+/', '_', $name), '_');

        if ($name === '') {
            $name = self::spell($value);
        }

        if ($name === '') {
            return 'empty';
        }

        if (preg_match('/^\d/', $name) === 1) {
            $name = "_$name";
        }

        return strtolower($name) === 'class' ? 'class_' : $name;
    }

    /**
     * Spell a punctuation-only string out in words so it survives as an
     * identifier: `*` -> `asterisk`, `/` -> `slash`, `->` -> `minus_gt`.
     */
    public static function spell(string $value): string
    {
        $words = [];
        $length = strlen($value);

        for ($i = 0; $i < $length; $i++) {
            $words[] = self::SYMBOLS[$value[$i]] ?? null;
        }

        return implode('_', array_filter($words, static fn(?string $word): bool => $word !== null));
    }

    // ─── String shaping ────────────────────────────────────────────────

    /**
     * PascalCase, splitting on any run of non-alphanumerics and prefixing `_`
     * when the result would start with a digit.
     */
    public static function pascal(string $raw): string
    {
        $parts = preg_split('/[^A-Za-z0-9]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $out = implode('', array_map(ucfirst(...), $parts));

        return preg_match('/^\d/', $out) === 1 ? "_$out" : $out;
    }

    public static function camel(string $raw): string
    {
        return lcfirst(self::pascal($raw));
    }

    /** snake_case, splitting camelCase humps as well as punctuation. */
    public static function snake(string $raw): string
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $raw) ?? $raw;
        $parts = preg_split('/[^A-Za-z0-9]+/', $spaced, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return strtolower(implode('_', $parts));
    }

    // ─── Pluralisation ─────────────────────────────────────────────────

    public static function singular(string $word): string
    {
        $lower = strtolower($word);

        if (in_array($lower, self::UNCOUNTABLE, true) || isset(self::IRREGULAR[$lower])) {
            return $word;
        }

        $irregular = array_search($lower, self::IRREGULAR, true);

        if ($irregular !== false) {
            return $irregular;
        }

        // Only endings that genuinely need `es` stripped are listed. A plain
        // `-ses` and `-zes` are left to the final rule so `enterprises` and
        // `prizes` become `enterprise` and `prize` rather than losing a letter;
        // `statuses` is covered by IRREGULAR above.
        //
        // Known limitation: a `ch`/`sh` stem that already carries an `e` loses
        // it, so `caches` singularises to `cach`. The plural always round-trips,
        // so only the singular reads oddly; add such a word to IRREGULAR if it
        // ever matters.
        foreach (['ies' => 'y', 'sses' => 'ss', 'zzes' => 'zz', 'ches' => 'ch', 'shes' => 'sh', 'xes' => 'x'] as $suffix => $replacement) {
            if (str_ends_with($lower, $suffix)) {
                return substr($word, 0, -strlen($suffix)) . $replacement;
            }
        }

        return str_ends_with($lower, 's') && !str_ends_with($lower, 'ss')
            ? substr($word, 0, -1)
            : $word;
    }

    public static function plural(string $word): string
    {
        $lower = strtolower($word);

        if (in_array($lower, self::UNCOUNTABLE, true)) {
            return $word;
        }

        if (isset(self::IRREGULAR[$lower])) {
            return self::IRREGULAR[$lower];
        }

        if (preg_match('/[^aeiou]y$/', $lower) === 1) {
            return substr($word, 0, -1) . 'ies';
        }

        return preg_match('/(s|x|z|ch|sh)$/', $lower) === 1 ? "{$word}es" : "{$word}s";
    }

    // ─── Paths ─────────────────────────────────────────────────────────

    /**
     * The `{placeholder}` names in a path, in path order — exactly the order
     * `AdminApi::$pathParams` needs. Names are passed through verbatim: they go
     * into a `str_replace`, so `enterprise-team` and multi-segment values
     * containing `/` both work without escaping.
     *
     * @return list<string>
     */
    public static function pathParameters(string $path): array
    {
        preg_match_all('/\{([^}]+)\}/', $path, $matches);

        return $matches[1];
    }

    /**
     * Path segments that name a resource: no leading version segment, no
     * `{placeholder}` segments.
     *
     * @return list<string>
     */
    public static function resourceSegments(string $path): array
    {
        $segments = array_values(array_filter(
            explode('/', $path),
            static fn(string $segment): bool => $segment !== '' && !str_starts_with($segment, '{'),
        ));

        if (isset($segments[0]) && preg_match('/^v\d+$/i', $segments[0]) === 1) {
            array_shift($segments);
        }

        return $segments;
    }

    /** Whether the path's final segment is a `{placeholder}` — a single item. */
    public static function endsWithParameter(string $path): bool
    {
        return preg_match('/\{[^}]+\}\/?$/', $path) === 1;
    }

    // ─── Registry ──────────────────────────────────────────────────────

    /**
     * Reserve `$base`, appending `2`, `3`, ... until it is unique.
     *
     * @param array<string, true> $taken
     */
    private function claim(string $base, array &$taken): string
    {
        $name = $base;
        $suffix = 1;

        while (isset($taken[$name])) {
            $name = $base . ++$suffix;
        }

        $taken[$name] = true;

        return $name;
    }

    /**
     * Reserve the first free candidate, falling back to a discriminator on the
     * first candidate when every option is taken.
     *
     * @param non-empty-list<string> $candidates
     * @param array<string, true>    $taken
     */
    private function claimFirst(array $candidates, array &$taken): string
    {
        foreach ($candidates as $candidate) {
            if (!isset($taken[$candidate])) {
                $taken[$candidate] = true;

                return $candidate;
            }
        }

        // Every candidate is taken. Discriminate the most specific one, so a
        // PATCH still reads as a patch rather than borrowing `update`.
        return $this->claim($candidates[array_key_last($candidates)], $taken);
    }
}
