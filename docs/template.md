# The template and its derived packages

This repository is a template. Packages made from it — `zero-to-prod/github-api`,
`zero-to-prod/stripe-api`, and so on — are not copies that drift away from it.
They keep this repository as a git ancestor, and they keep pulling from it, so
that a fix to the transport layer or a new check in `scripts/` is written once
here and merged into every package that wants it.

Everything below rests on one idea: **the template owns every file unless a
derived package explicitly says otherwise.** There is no positive list of shared
files. There is only a short list, in each derived package, of the files that
package has claimed for itself.

## Vocabulary

- **template** — this repository, and also the literal name of the git remote
  that every derived package uses to point at it.
- **derived package** — a repository created from the template. Its `origin` is
  its own GitHub repository; its `template` remote is this one.
- **owned file** — a file a derived package has claimed via `merge=keepours` in
  its `.gitattributes`. Template changes to owned files are dropped on merge.

## Creating a new package

Run `composer new-package -- <slug> --org=<org>` to print this sequence with the slug
filled in, then run it yourself.

```bash
git clone <template-url> my-sdk
cd my-sdk

# The template becomes the upstream. Your own repo becomes origin.
git remote rename origin template
gh repo create <org>/my-sdk --source=. --remote=origin

# Once per clone. See "The driver is not in .gitattributes" below.
git config merge.keepours.driver true

# Rebrand and build: prompts for the package identity, rewrites namespaces,
# class names, composer.json and sdk.json, renames the files that carry a name,
# installs dependencies, generates the models from your OpenAPI document, and
# runs `composer fix`. The package is ready for `composer test` afterwards.
php init

git add -A && git commit -m "Initialise <org>/my-sdk from template"
git push -u origin main
```

Cloning rather than using GitHub's "Use this template" button is the whole
point: a clone carries the template's commits, so `git merge` has a common
ancestor to work from. A template-button repository starts with a single squashed
commit and shares no history with anything, which turns every later merge into a
whole-tree conflict.

`php init` asks for one thing it will not let you skip: the OpenAPI document. A
package with no models is not finished, and the generator reads that document to
write `src/Models/` and `src/ApiRoute.php` before the script ends.

`php init` deletes itself when it finishes. That is deliberate — it is a one-time
rebranding, and a second run would try to rename files that no longer carry
template names.

## Retrofitting a repository that was copied, not cloned

If a package already exists and was created by copying files, you can still graft
the ancestry on. Order matters here.

```bash
git remote add template <template-url>
git fetch template
git config merge.keepours.driver true
```

**De-brand first.** Before grafting, make the package's own tree look like what
the template would have produced: same file names, same layout, branding
differences pushed into `sdk.json` rather than scattered through the code. Commit
that. Doing this first means the graft merge compares two trees that differ only
where they genuinely differ, instead of differing everywhere a name appears.

Then graft. This records the template as an ancestor without changing a single
file in your tree:

```bash
git log --oneline template/main          # find the commit you copied from
git merge -s ours --allow-unrelated-histories <template-sha> \
    -m "Graft template history: <template-url>"
```

`-s ours` keeps your tree exactly as it is and adds the template commit as a
second parent. From that moment git can compute a merge base between the two
histories, which is the only thing that was missing.

`--allow-unrelated-histories` is not optional here. Two trees that never shared a
commit are exactly what git refuses to merge, and it refuses before it looks at
the strategy, so `-s ours` on its own fails with `fatal: refusing to merge
unrelated histories` -- the same error that sent you to this section.

**Graft at the commit you copied from, never at `template/main`.** The graft sets
the merge base, and `-s ours` declares your side the winner for everything up to
it. Graft at the tip and you have told git that every template change ever made
is already merged and resolved in your favour: `git merge template/main` answers
`Already up to date`, and the work you were trying to pull in is unreachable
forever. Pick the oldest commit that makes sense -- the template's root commit is
a safe default -- because only template work *after* the graft point can ever
merge. The step below is what actually brings the template's code in; if it
reports nothing to do, you grafted too late.

Now declare what the package owns (next section), commit that, and take the
first real merge:

```bash
git merge template/main
```

Expect conflicts on this first merge and resolve them by hand. It is the one
merge that has to reconcile two independently written trees; afterwards the
shared base moves forward with you and merges get small.

## Steady state, forever

If the package has an OpenAPI source (defined in `sdk.json`):

```bash
git pull template main && composer generate-sdk && composer check && git push
```

If the package is hand-maintained (no OpenAPI source):

```bash
git pull template main && composer check && git push
```

That is the whole ongoing relationship. Run it whenever the template gains
something you want, or on a schedule, or before starting a piece of work.

**Why `generate-sdk`?** The generated files (`src/ApiRoute.php`, `src/Models/**`,
`factories/**`) are never merged — the generator is the source of truth, not the
files. When you pull from the template, regenerate models to pick up generator
improvements, then merge your OpenAPI changes with those improvements.

**Never `git pull --rebase` from the template.** Rebasing rewrites your commits
onto the template's tip, which discards the merge commits that record the shared
ancestry. Once they are gone, the next merge has no useful merge base and replays
the entire template history as conflicts. There is no clean recovery except
rebuilding the graft by hand.

The trap is that this can happen without you typing `--rebase`. If you have

```bash
git config --global pull.rebase true
```

then the ordinary `git pull template main` *is* the destructive command. Check
with `git config --get pull.rebase`; if it is true, use the explicit form:

```bash
git pull --no-rebase template main
```

## Declaring what a derived package owns

Ownership is declared by `merge=keepours` lines appended at the **bottom** of the
derived package's `.gitattributes`, below the template's own lines. The template
never contains such lines — it owns everything by default.

```
# ... template's export-ignore lines stay above ...

sdk.json               merge=keepours
README.md              merge=keepours
src/ApiRoute.php       merge=keepours
src/Models/**          merge=keepours
factories/**           merge=keepours
.env                   merge=keepours
```

Bottom placement is a convention that keeps the diff between a derived package
and the template readable: everything above the block came from the template and
should merge cleanly, everything below is this package's declaration about
itself.

For this template that list is the expected surface:

- **`sdk.json`** — the package's identity. Name, namespace, class names, base
  URL, OpenAPI source, and `retain_models`. It exists precisely so that identity
  is one file instead of a thousand string literals.
- **`README.md`** — describes this product, not the shared scaffold. `php init`
  deletes `tests/Unit/ReadmeExamplesTest.php` with it, because mirroring the
  template's code blocks asserts nothing about your package.
- **`src/ApiRoute.php`**, **`src/Models/**`**, **`factories/**`** — generated from
  the package's own OpenAPI document. They are regenerated, never merged; a
  template change to a generated file is meaningless because the generator, not
  the file, is the shared thing. Note that generation *deletes* here as well as
  writes: every `src/Models/*.php` outside `retain_models` goes, along with the
  matching `factories/<Model>Factory.php`. Anything hand-written you want to keep
  under `src/Models/` belongs in `retain_models`. A name in that list is reserved,
  not merely spared: a document schema that collides with it takes a discriminator
  instead of overwriting the file, and a run refuses to start while one of those
  files is missing — nothing in the document can recreate a hand-written class, so
  the alternative is a package whose own `src/` references classes it does not
  have. Removing one for good means removing it from `retain_models` too.
- **`.env`** — local, per-checkout configuration.

Everything else — the transports, hooks, `Internal/`, `scripts/`, `docker/`,
`run`, the linter configs, **and the test suite** — stays template-owned. If you
find yourself wanting to claim one of those, that is usually a sign the change
belongs in the template instead.

The test suite is worth spelling out, because it is the part that most looks like
it should be owned locally and most must not be. `tests/` is where the guarantee
for the shared code lives — the dispatcher, the transports, the hooks, the query
normalizer, `ApiResult`, `Response`, `Route` — so it has to keep merging down
from the template forever. That is only possible because nothing in it names a
generated symbol:

- The shared tests dispatch against `tests/Fixtures/FixtureRoute`, a permanent
  fixture enum selected per client with `SdkConfig::route_enum`. `generate-sdk`
  rewrites `src/ApiRoute.php`, and cannot touch `tests/Fixtures/`.
- The three files that *were* template-specific — `InitTest`,
  `ReadmeExamplesTest` and `ExampleDomainTest` (the only file naming the shipped
  `Widget` example domain) — are deleted by `php init`.

So the rule for a derived package is: add your own test files, never claim
`tests/**` as `merge=keepours`, and never reference a generated model or route
case from a file the template also ships.

### The driver is not in `.gitattributes`

`.gitattributes` says *which* files use a merge driver called `keepours`. It does
not say what `keepours` does. That lives in `.git/config`, which git never
transfers between repositories, so every fresh clone must run this once:

```bash
git config merge.keepours.driver true
```

`true` is `/bin/true`: it exits 0 without writing anything, so git accepts the
file as already merged and your version stands.

Without the driver, git finds an attribute naming a driver it does not have, and
**falls back to an ordinary conflicting merge without warning you.** A clone that
skipped the command looks completely fine until the first merge, at which point
files you thought were protected come up as conflicts — or worse, are resolved
toward the template by whoever is clearing conflicts. This is the single most
common way the arrangement fails, so `composer check-template` fails when it is
unset.

The key is `merge.keepours.driver`, and nothing validates a config key you invent.
`merge.driver.keepours.driver` sets a key git never reads, and behaves exactly like
having set nothing at all. `composer check-template` reads the one real key, so run
it rather than trusting that the command you typed took.

### Two things that must never be `merge=keepours`

**Lockfiles.** `composer.lock`, `package-lock.json`, and friends. Pinning a
lockfile to your side while merging code that needs newer dependencies does not
produce a conflict — it produces a repository that installs the old versions and
fails much later with a missing class or function, a long way from the merge that
caused it. Let lockfiles conflict, then resolve them the only way a lockfile can
be resolved: regenerate.

```bash
git checkout --theirs composer.lock || true
composer update --lock
```

**Anything that differs only for branding.** If a file is claimed because it
contains the package's name, URL, or class name, the claim will silently drop
every real improvement the template ever makes to that file. Move the differing
value into `sdk.json` and let the file merge. `php init` and the generators in
`scripts/` exist to make that possible.

## Checking the wiring

```bash
composer check-template
```

In a derived package this fails when the `keepours` driver is unset or a lockfile
is claimed, warns when the `template` remote is missing, and reports how many
template commits are unmerged as of the last fetch (it does not fetch — a check
should not touch the network). In the template itself the same findings are
printed as advisory notes and the script exits 0, so `composer check` stays
green here.
