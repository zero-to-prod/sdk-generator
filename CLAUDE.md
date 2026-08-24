## CLI Commands

```
Usage: composer <script>

Scripts:
  check                Run all checks (tests, PHPStan, CS Fixer, Rector, template, route + link annotations, CLAUDE.md, README)
  fix                  Apply all fixes (Rector, CS Fixer, generated annotations and docs) then re-check
  test                 Run tests for the PHP version set in .env
  test-all             Run tests for all PHP versions
  analyse              Run PHPStan static analysis at level 9
  lint                 Run PHP CS Fixer in dry-run mode (no changes)
  format               Run PHP CS Fixer and apply fixes
  rector               Run Rector and apply fixes
  rector-lint          Run Rector in dry-run mode (no changes)
  coverage             Generate code coverage report for src/
  coverage-api         Generate code coverage report for public API classes only
  check-template       Verify the template ancestry wiring (template remote, keepours driver, lockfiles)
  check-routes         Verify @method PHPDoc on ApiRoute matches #[HasRoute] cases
  check-api-methods    Verify @method PHPDoc on SdkApi matches #[AdminApi] attributes
  check-links          Verify @link annotations exist on model classes and ApiRoute cases
  check-claude-md      Verify CLAUDE.md CLI commands section is up-to-date
  check-readme         Verify README.md public API section is up to date
  check-toc            Verify README.md Table of Contents section is up-to-date
  generate-sdk         Generate src/Models and src/ApiRoute.php from the OpenAPI document in sdk.json
                         --models-only  Write src/Models only
                         --routes-only  Write src/ApiRoute.php only
                         --webhooks     Also emit webhook payload models
                         --all-schemas  Emit every component schema, not only the reachable ones
                         --dry-run      Print the plan and write nothing
                         --force        Overwrite uncommitted changes under the generated paths
                         --verbose      List every skipped construct
                         --out=<dir>    Write into <dir> instead of the package root
  generate-routes      Generate @method PHPDoc on ApiRoute enum from #[HasRoute] cases
  generate-api-methods Generate @method PHPDoc on SdkApi from #[AdminApi] attributes
  generate-links       Generate @link annotations on model classes and ApiRoute cases
  generate-claude-md   Generate CLI commands section in CLAUDE.md from the composer.json scripts
  generate-readme      Generate public API section in README.md from the package CLI
  generate-toc         Generate Table of Contents section in README.md from ##/###/#### headings
  sdk                  Run the package CLI tool
  deps                 Run a composer command for the PHP version set in .env
  deps-all             Run a composer command for all PHP versions
  setup                Initialize project: copy .env.example and pull doc repos
  new-package          Print the command sequence for creating a derived SDK package
                         --org=<org>  GitHub org or user that will own the new repository
```

<!-- end cli commands -->

## API Method Naming Convention

Public methods on `SdkApi` — declared via the `name` arg of `#[AdminApi]` on `ApiRoute` cases — follow Google-Cloud-style `verb<Resource>` naming, where the verb maps 1:1 to the HTTP method and collection vs. single-item semantics:

| HTTP                    | Verb     | Example                       |
|-------------------------|----------|-------------------------------|
| `GET /resources/{id}`   | `get`    | `getWidget($id)`              |
| `GET /resources`        | `list`   | `listWidgets($opts)`          |
| `POST /resources`       | `create` | `createWidget($data)`         |
| `PATCH /resources/{id}` | `update` | `updateWidget($id, $data)`    |
| `PUT /resources/{id}`   | `update` | `updateWidget($id, $data)`    |
| `DELETE /resources/{id}`| `delete` | `deleteWidget($id)`           |

Rules:

- Singular resource for `get`/`update`/`delete` (`getWidget`), plural for `list` on a collection endpoint (`listWidgets`); `create` posts to the collection but names the single resource it creates (`createWidget`).
- Nest the parent in the name for sub-resources (`createWidgetPart`, not `createPart`).
- `PUT` and `PATCH` both map to `update`. When one path declares both, the generator keeps
  `update<Resource>` for `PUT` (the full replacement) and names the `PATCH` variant
  `patch<Resource>`, rather than emitting two methods with the same name.
- No vendor- or provider-specific jargon in names — the client is provider-agnostic. Filter semantics (e.g. looking up by an upstream UUID) belong in the `where[...]` query param, not the method name.
- The response *shape* never changes the name. A list endpoint that responds with a bare JSON array (`[{...}]`) is still `list<Resource>`; it only declares `listOf: Element::class` instead of `response: Model::class`, and `ApiResult::$data` comes back as `array<int, Element>`.
- When adding a new route, add a new `ApiRoute` case with `#[HasRoute]` + `#[AdminApi]`, then run `composer fix` to regenerate `@method` PHPDoc, README, and `@link` annotations.

## Template

This repo is the SDK **template** — the common ancestor of every derived SDK package. Work here as if the change ships to every descendant, because it does.

- **Identity lives in `sdk.json`** — composer `name`, `namespace`, `title`, `description`, `api_class`, `config_class`, `bin`, `docs_url`, `retain_models`, and the `openapi` block. Every script and `bin/sdk` reads it (PHP scripts via `scripts/manifest.php`, bash via `php -r`). Never hardcode a package name, namespace, class, CLI name, or docs URL in shared tooling — a derived package must inherit these files untouched, so a hardcoded value becomes a permanent merge conflict downstream.
- **`src/Models/` and `src/ApiRoute.php` are GENERATED** by `composer generate-sdk` from the OpenAPI document declared in `sdk.json` (`openapi.source`). Do not hand-edit them in a generated package — rerun the generator. A package with `openapi.source: null` is hand-maintained: edit `src/ApiRoute.php` and `src/Models/` directly, then `composer fix`.
- **Generation OWNS `src/Models/` — it deletes as well as writes.** `src/ApiRoute.php` is replaced wholesale. Before writing models, the run deletes every `src/Models/*.php` whose class name is not in `sdk.json`'s **`retain_models`** (`Errors`, `Pagination`, `Query`), plus the matching `factories/<Model>Factory.php`. That is what stops a previous document's models — or the shipped `Widget` example domain — lingering as orphans no route references. `--dry-run` reports the intended deletions; the summary counts them on a `deleted` line. Add a hand-written model to `retain_models` or the next run removes it. A run then regenerates the `@method` block on the API class, because that block is derived from the `ApiRoute` it just wrote — a stale one names swept models and fails PHPStan. Still run `composer fix` afterwards for `@link` annotations and to strip imports of swept models.
- **Everything else in `src/`** (transports, `SdkApi` dispatch, `ApiResult`, hooks, `Options`, `Query`) is hand-written template code shared by all descendants.
- **The shared test suite must never name a generated symbol.** `SdkConfig::route_enum` selects the enum the dispatcher resolves against, and every test of the shared code dispatches `tests/Fixtures/FixtureRoute` — never `ApiRoute`. The shipped `Widget` example domain is named in exactly one file, `tests/Unit/ExampleDomainTest.php`, which is a smoke test. If you add a test that names `Widget*` or an `ApiRoute` case anywhere else, it will break in every derived package.
- **`retain_models` protects a model from deletion, not from being overwritten.** A document that declares its own `Errors`, `Pagination` or `Query` schema replaces `src/Models/<Name>.php`. So the only `src/Models` classes the shared suite may name are the ones the shared client code itself depends on — `Errors` (`ApiResult`, `SdkApi`) and `Query` (`QueryNormalizer`, `Options`) — because a document that reshapes those breaks `src/`, not just the tests. `Pagination` is example content: the fixtures own `tests/Fixtures/Models/FixturePagination` and its factory, and the shipped `Pagination`/`PaginationFactory` are named only in `ExampleDomainTest`.
- **`php init` is the whole setup flow**: clone, run it, answer the prompts, and the package is ready for `composer test`. It prompts (interactively only — no flags, no answers file), rewrites every template token, renames the files carrying a name, then deletes template-only content: itself, its `.gitattributes` line, `tests/Unit/InitTest.php`, `tests/Unit/ReadmeExamplesTest.php` (a derived package rewrites its README) and `tests/Unit/ExampleDomainTest.php`. It then writes `.env`, installs dependencies into `vendor/` and `.vendor/php8.x`, runs `scripts/generate-sdk --force` against the OpenAPI document it required at the prompt, and runs `composer fix`. `SDK_INIT_SKIP_BUILD=1` stops it after the rewrite; `tests/Unit/InitTest.php` sets that and drives the real script with answers on stdin.
- **`config.platform.php` in composer.json pins resolution to `8.1.0`**, the oldest version the package supports, and `composer.lock` is gitignored. `php init` installs on the host first (PHP 8.5 here) and then into the 8.1 container from the lock the host just wrote, so without the pin the host resolves packages the oldest container cannot install and the container step fails on every locked constraint. The cost is that all five versions test the 8.1-compatible dependency set — `illuminate/*` resolves to 10.x, never the 11/12 that `require-dev` also allows.
- **`docs/template.md`** holds the ancestry runbook: creating a derived package, pulling template updates, and the merge strategy for generated vs. hand-maintained files.
