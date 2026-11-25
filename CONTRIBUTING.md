<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Contributing to Tesserarr

Thanks for your interest in contributing. This document explains how to take part and what a pull request needs before it
can be merged.

## Code of Conduct

This project follows the [Contributor Covenant 2.1](https://www.contributor-covenant.org/version/2/1/code_of_conduct/). By
taking part you agree to its terms. Report unacceptable behaviour to `projects@strausmann.ch`.

## Contributor License Agreement

Contributions need a signed [Contributor License Agreement](CLA.md). You sign it once through
[CLA assistant](https://cla-assistant.io/): when you open your first pull request, the bot comments with a link; follow it,
sign in with GitHub and accept. The `license/cla` check must pass before a pull request can be merged.

While the repository is private, CLA assistant cannot see it, so the `license/cla` check never reports and is not yet a
required check of the ruleset `main: licence gate`. It becomes one when the repository is made public.

## No environment details, ever

This repository must never contain anything that identifies a real installation: not in code, issues, commit messages, pull
requests, documentation, examples, the Docker Hub description or the image. That includes host names, domains, IP
addresses, user names, account identifiers, share names, file system paths of a real machine, tokens and screenshots that
show any of them.

| Instead of a real value | Write                                  |
| ----------------------- | -------------------------------------- |
| a host name             | `media.example.org`                    |
| an IP address           | `192.0.2.10` (documentation range)     |
| a path                  | `/media/movies`, `/syncthing/outgoing` |
| a user                  | `alice`, `bob`                         |
| a token                 | `<token>`                              |

This project hands out access to a private library. Anything that maps a configuration to a real person or machine makes
that person easier to target. The `Privacy / secret scan` check rejects obvious leaks; it cannot catch everything.

## Ways to contribute

| Contribution          | Where                                                          |
| --------------------- | -------------------------------------------------------------- |
| Report a bug          | [Issues](../../issues/new)                                     |
| Suggest a feature     | [Issues](../../issues/new)                                     |
| Improve documentation | Pull request directly                                          |
| Security issue        | Write to `security@strausmann.ch` (see [SECURITY.md](SECURITY.md)); do **not** open a public issue |

## Development workflow

### 1. Clone

```bash
gh repo clone strausmann/tesserarr
cd tesserarr
```

### 2. Set up your environment

Docker is the only requirement; PHP 8.5, Composer, Node and PostgreSQL run in containers.

```bash
cp .env.example .env
mkdir -p .dev/backups && sudo chown 10001:10001 .dev/backups   # the image runs as UID 10001
docker compose --env-file docker/base-images.env -f compose.dev.yaml build
docker compose --env-file docker/base-images.env -f compose.dev.yaml run --rm --no-deps web artisan key:generate --show   # put the key into .env as APP_KEY
docker compose --env-file docker/base-images.env -f compose.dev.yaml up
```

The base images of `docker/Dockerfile` are pinned in `docker/base-images.env` only, and every build passes them as build
arguments: Compose reads them with `--env-file docker/base-images.env`, `docker build` with
`$(python3 scripts/base_images.py cli)`. A build without them stops with "base name should not be blank".

`up` first runs the one-off `migrate` service (`artisan tesserarr:migrate`); `web`, `worker` and `scheduler` start only
after it has finished successfully. When migrations are pending, `tesserarr:migrate` first writes a `pg_dump` backup to
`TESSERARR_BACKUP_DIR` (in the dev stack `.dev/backups/`), keeps the newest five (`TESSERARR_BACKUP_KEEP`, 1 to 50)
and stops without migrating when the backup fails or is empty. Nothing pending means no backup. A satellite never
migrates: it has no database. The web interface is then on <http://localhost:8080>.

The dev database is PostgreSQL 18; its data lives in `.dev/postgresql/`. A data directory of an older major version
cannot be opened by PostgreSQL 18: a stack from before the switch starts with an empty database, and an old
`.dev/postgres/` can be deleted.

### 3. Create a branch

Branch from the milestone branch `milestone/X.Y.0` for a feature, and from `main` for a fix, docs, CI or another change
that does not release on its own. Which base a pull request targets decides what it may contain, see
[Milestones, branches and releases](#milestones-branches-and-releases).

| Prefix                             | Use for                                                                        |
| ---------------------------------- | ------------------------------------------------------------------------------ |
| `milestone/X.Y.0`, `milestone/X.Y.x` | Integration branch of a minor or major milestone, or of a patch milestone (maintainers create it) |
| `feat/`                            | New feature                                                                    |
| `fix/`                             | Bug fix                                                                        |
| `docs/`                            | Documentation only                                                             |
| `refactor/`                        | Code change without behaviour change                                           |
| `test/`                            | Test additions or changes                                                      |
| `chore/`                           | Tooling, dependencies                                                          |
| `ci/`                              | CI changes only                                                                |

Example: `git checkout -b feat/invitation-expiry origin/milestone/0.1.0`.

### 4. Make changes with TDD

Every behaviour change starts with a failing test:

1. Write the test and watch it fail for the expected reason.
2. Make it pass with the smallest change.
3. Run the counter-test: remove the one cause your change adds and check that the test turns red again, with the expected
   status code or exception. A test that stays green proves nothing; say so in the pull request.
4. Refactor if needed, then commit.

Tests use [Pest](https://pestphp.com) against PostgreSQL, never SQLite:

```bash
./vendor/bin/pest                         # all tests
./vendor/bin/pest tests/Unit              # unit tests only
docker build $(python3 scripts/base_images.py cli) -f docker/Dockerfile --target test .   # the test stage of the image
```

### 5. Commit messages: Conventional Commits

This is **mandatory**. [semantic-release](https://github.com/semantic-release/semantic-release) derives version numbers
and the changelog from commit messages, so the type matters. The `commitlint` check validates the pull request title,
which becomes the squash commit.

```
<type>(<scope>): <subject>

<body>

<footer>
```

| Type                                       | Effect on version | When to use         |
| ------------------------------------------ | ----------------- | ------------------- |
| `feat`                                     | minor             | New feature         |
| `fix`, `perf`                              | patch             | Bug fix, speed-up   |
| `docs`, `test`, `refactor`, `chore`, `ci`, `build`, `style` | none | Internal  |
| `feat!` or a `BREAKING CHANGE:` footer     | major             | Breaking change     |

The subject is lower case after the colon and has no full stop. Link the issue in the footer (`Closes #N` or `Refs #N`).

### 6. Push and open a pull request

- Describe what changed and why, list side effects, link the issue.
- Paste the results of the counter-tests from step 4.
- Wait for CI and the reviews.

Task and topic pull requests are squash-merged; the squash commit message must follow Conventional Commits. The milestone
pull request and the sync pull requests are merged with a merge commit, see
[Milestones, branches and releases](#milestones-branches-and-releases).

### 7. Code review by humans and bots

| Reviewer                              | What they look at                                                    | How to read their feedback                       |
| ------------------------------------- | -------------------------------------------------------------------- | ------------------------------------------------ |
| **Maintainer**                        | Architecture fit, decisions, security, judgement calls               | Authoritative                                    |
| **Codex** (`chatgpt-codex-connector`) | Every pull request, with access to the whole repository: bugs, cross-file effects, security | Take it seriously; reply when you disagree |
| **GitHub Copilot**                    | When requested on a pull request                                     | Same: read every comment, fix or reply           |

1. Open the pull request (a draft is fine).
2. Wait for green CI and the Codex review. After new commits, comment `@codex review` to request a new one.
3. Read every bot comment. Fix it or reply with the reason you do not.
4. Mark the pull request ready; the maintainer reviews and merges.

A pull request is not ready while CI is red, a bot finding is neither fixed nor answered, or the title does not follow
Conventional Commits. One change per pull request: an unrelated bug found on the way gets its own issue and pull request.

## Triage

A maintainer sets the labels while triaging: one `type:` label (`type:bug`, `type:feature`, `type:docs`, `type:chore`,
`type:refactor`, `type:test`, `type:question`, `type:security`), the `area:` labels it touches, a `priority:` label and a
`status:` label (`status:needs-triage`, `status:needs-info`, `status:in-progress`, `status:blocked`,
`status:implemented`). Every issue gets the milestone of the release it ships in when it is opened.

## Local CI checks

The CI jobs run the same commands. All of them are hard gates; none is warn-only.

```bash
composer validate --strict
./vendor/bin/pint --test                       # formatting (Laravel preset, strict types)
./vendor/bin/phpstan analyse                   # Larastan, level 8, no baseline, worker-safe code
composer audit --locked --abandoned=fail
./vendor/bin/pest --coverage --min=80 --coverage-clover=clover.xml
php scripts/coverage_gate.php clover.xml       # per-directory minimums, as listed in .github/workflows/ci.yml
npm run types                                  # svelte-check
npm run lint                                   # Oxlint (type-aware, warnings fail) and the formatter
npm run test:coverage                          # Vitest; resources/js/lib at least 80 %
npm run build
scripts/e2e/run.sh                             # Playwright against the built image (see "Browser tests")
python -m pytest scripts/                      # release guard and registry rules (standard library only)
python3 scripts/base_images.py check           # base image pins on the allowlist, matching docker/Dockerfile
python3 scripts/lockfile_sources.py            # locked downloads only from the expected hosts
```

| Check                               | Job in CI                                  |
| ----------------------------------- | ------------------------------------------ |
| Pint, Larastan, audit, migrations, Pest, coverage gate | `PHP — lint, static analysis, test` |
| svelte-check, lint, tests, build    | `Web — typecheck, lint, test, build`       |
| Image build and smoke tests         | `Docker — build smoke test`                |
| Licences of every dependency        | `Licence gate (composer, npm)`             |
| Self-tests of the scripts           | `Scripts — self-tests`                     |
| Base image pins and their allowlist | `Base image pins`                          |
| Download hosts of the lockfiles     | `Lockfile sources`                         |
| Version impact of a pull request    | `release-impact`                           |
| Migrations from the last release    | `Upgrade — migrations from the last release` |
| Static analysis for security bugs   | `Semgrep`                                  |
| Private network artefacts, secrets  | `Privacy / secret scan`                    |
| SPDX header or annotation per file  | `REUSE — licensing of every file`          |
| Vale on the English docs            | `Prose`                                    |
| Pull request title                  | `commitlint`                               |

Pull requests into `main` additionally need the checks of the ruleset `main: licence gate`:
`Licence gate (composer, npm)`, `release-impact` and `Docker — build smoke test` (and `license/cla` once the repository is
public, see "Contributor License Agreement").

**Coverage.** The whole application needs 80 %. Security-relevant directories need more: `app/Authorization`,
`app/Invitations`, `app/Mfa`, `app/Identity` and `app/Tokens` 90 %; `app/Execution`, `app/Catalog`, `app/Satellites` and
`app/Connections` 85 %. `scripts/coverage_gate.php` checks a directory only once it exists, so the pull request that
creates one of these directories adds its `<path>:<min>` to the coverage gate step in `.github/workflows/ci.yml`. A
directory that exists but has no lines in the report fails.

**Mutation tests** run nightly (`pest --mutate --min=70`) on the directories that carry authorisation, invitations,
second factors, execution and the catalogue.

### Worker-safe code

Tesserarr will run in FrankenPHP worker mode with Laravel Octane. A worker boots the application once and serves many
requests, of different users, from the same memory. Whatever one request leaves behind, the next request of another user
sees. For Tesserarr that is a data leak between friends. Code is written for worker mode now, before Octane arrives.

Rules:

- **No state in `static`.** No static property, no `static` variable in a function, no `global`. That covers caches too:
  a static cache keyed by anything less than the full request context serves the first user's answer to the next.
- **`scoped()`, not `singleton()`, for anything that depends on the request.** A singleton must not be built from the
  request, the auth guard or the signed-in user, the session, the configuration or the locale. That holds for its
  constructor and for the closure that builds it. Read such values per call, or bind the service with `scoped()`.
- **No public property that anyone can write** on a service, a singleton, a controller or a middleware. Use `readonly`
  or a private property. Eloquent models and queued jobs are exempt: each is one object per row or job.
- **No runtime writes to the configuration**: no `config([...])`, `Config::set()` or `config()->set()` in request code.
  Pass the value to the code that needs it.
- **No `App::setLocale()` outside a middleware.** A middleware sets the locale for each request, and Octane resets it
  after the request.
- **No `View::share()` and no Inertia shared props from a boot method.** Shared Inertia props are built per request in
  `HandleInertiaRequests::share()`.
- **Global scopes read their value inside the closure.** `booted()` runs once per worker, so a value captured there is
  the first request's value.
- **The rate limiter never uses the `array` cache store.** Octane empties it between requests, and the limits of the
  sign-in would count nothing.
- **No `exit` or `die`, no `register_shutdown_function()`**, and no calls that change the process for every later
  request (`setlocale()`, `umask()`, `chdir()`, `putenv()`, `ini_set()`, `date_default_timezone_set()`,
  `set_error_handler()`, `set_exception_handler()`, writes to `$_ENV`).

```php
// Leaks: the first user's shares are served to everyone after them.
final class ShareList
{
    private static array $cache = [];

    public function for(User $user): array
    {
        return self::$cache['shares'] ??= $user->shares()->get()->all();
    }
}

// Leaks: the guard of the first request stays in the singleton.
$this->app->singleton(ShareList::class, fn ($app) => new ShareList($app->make(Guard::class)));

// Safe: built per request, and the user is passed per call.
$this->app->scoped(ShareList::class);
```

**How it is checked.** `phpstan analyse` enforces the rules above in `app/` and `routes/`, with the rules in
`tests/PHPStan/worker-safety.neon` (own rules in `tests/PHPStan/Rules/Worker/` and
[spaze/phpstan-disallowed-calls](https://github.com/spaze/phpstan-disallowed-calls)). Each rule has a test against a
fixture in `tests/PHPStan/Fixtures/`. `tests/Feature/Runtime/RateLimiterStoreTest.php` checks the rate limiter store.

**Exceptions.** An exception needs a reason in the code. For the own rules, a comment that starts with
`worker-safe:` and says why the value holds no request data. For a disallowed call,
`// @phpstan-ignore <identifier> (<reason>)`; PHPStan refuses an ignore without a reason. A bare marker is not enough.

**The two-user test.** Every page and controller that loads user-specific data gets a case with
`assertNoStateLeakBetweenUsers()` (`tests/Pest.php`, cases in `tests/Worker/UserPagesTest.php`). It sends a request as
user A, then as user B, then as a guest through one Octane worker, without booting in between, and the same with B
first. It fails when A's response does not show A's data, because then the test proves nothing. It fails when B or the
guest sees anything of A. The helper wraps Octane's test worker (`tests/Support/Worker/OneWorker.php`), which Octane does
not document, so an Octane update breaks it in one place.

```php
it('shows each user only their own shares', function () {
    assertNoStateLeakBetweenUsers(
        fn () => UserRequest::as(workerUser('Alice'), Request::create('/shares'), 'Alice', 'Movies of Alice'),
        fn () => UserRequest::as(workerUser('Bob'), Request::create('/shares'), 'Bob', 'Series of Bob'),
    );
});
```

### Web UI: the on-screen keyboard and inputs

Tesserarr is used on phones. When a field has the focus and the on-screen keyboard is open, the field, its list and the
main buttons of a dialog must stay in sight, or be reachable by scrolling inside the dialog. Browsers make room for the
keyboard in two ways: Chromium resizes the layout viewport (`interactive-widget=resizes-content`), iOS Safari only shrinks
`window.visualViewport`. A layout that works in one can fail in the other. These rules apply to every interface with an
input:

- Every field goes through `resources/js/components/ui/Field.svelte`, with the fixed set of attributes for its purpose
  (`type`, `inputmode`, `autocomplete`, `autocapitalize`, `enterkeyhint`). Do not set them by hand on a bare `<input>`.
- Every dialog goes through `resources/js/components/ui/Dialog.svelte`.
- Anything fixed or sized to the screen uses `var(--vvh)`, the visible height published by `resources/js/lib/viewport.ts`,
  never `vh`, `dvh` or `window.innerHeight`.
- Text in fields is at least 16 px, so iOS does not zoom the page.
- A clear button (the small cross) appears only while the field has a value, is 44 px square and keeps the focus in the
  field.
- Every interface with an input gets a case in `e2e/keyboard.spec.ts`, in both modes: `resized` (layout viewport shrinks)
  and `covered` (the keyboard only covers the visual viewport). The case asserts that the field, its first option and
  the main buttons lie inside the visible area.

### Browser tests

`e2e/` holds the Playwright suite. It runs against the built image, as it ships, with PostgreSQL 18 next to it, in the
production environment. The only difference is the `e2e` stage of `docker/Dockerfile`: it adds `routes/testing.php` and
`app/Testing/`, which sign a test in through `/_testing/login/{role}`, and creates the marker file `.e2e-image`. The
route is registered only when the marker and the route file exist (`App\Runtime\E2eImage`). The published image contains
neither: the vendor stage deletes both files, `.dockerignore` keeps a marker out of the build context, and
`scripts/smoke/runtime.sh` checks that the route answers 404 even with `APP_ENV=testing`. No environment variable can
switch it on.

```bash
docker build $(python3 scripts/base_images.py cli) -f docker/Dockerfile --target e2e -t tesserarr:e2e .
npm ci
scripts/e2e/run.sh tesserarr:e2e               # extra arguments go to `playwright test`
```

The script runs the suite in the official Playwright container, pinned by digest, so no browser is installed on the
host. The container must be the same version as `@playwright/test` in `package-lock.json`; the script stops with a named
error when the two differ.

- **UI gate** (`e2e/ui-gate.spec.ts`, ADR-0006). Every view in `gatedViews` (`e2e/support/views.ts`) is rendered at 360,
  768 and 1440 px and at 360 × 420 (keyboard open). Each size runs in the light and the dark theme. It fails on a
  console error, an uncaught exception or a failed request (DNS, connection, abort). It fails on any 5xx answer, and on
  a 4xx answer for the page or one of its stylesheets, scripts, images, fonts or media. A 4xx answer to `fetch` or XHR
  is allowed. It also fails on horizontal scrolling and on any serious or critical axe violation. Tab walks every
  focusable element, at most 30, and each must show a visible ring. Screenshots are attached to the report; nothing is
  compared against them. Each rule has a self-test against a fixture page in `e2e/fixtures/` that breaks exactly that
  rule.
- **Artboards.** Each view names its artboard in the mockup canvas and lists what the artboard shows: headings, controls
  (`role:name`, found with `getByRole`) and states (`empty`, `error`, `loading`, `filled`). Every state has a fixture in
  `e2e/support/artboard.ts` that puts the view into it, and the artboard is checked again in every state. A task that
  builds or changes a view adds the view, its artboard and its states in the same pull request. A deliberate difference
  from the artboard is explained in the pull request.
- **On-screen keyboard** (`e2e/keyboard.spec.ts`). Every dialog, search and combobox adds a case to `keyboardCases`;
  each case runs in all four modes and uses `expectInSight` from `e2e/support/keyboard.ts`. A check on a real Android
  phone with Chrome and an iPhone with Safari stays part of the release acceptance.

## Website

The user documentation lives in [`website/`](website/) (Astro Starlight) and is published from `main`. Pull requests only
build it. English and German are maintained together: a change to a page under `website/src/content/docs/` is complete
only when the English and the German page change in the same pull request, and a parity check fails when a page exists in
one language only. Every feature comes with its page.

## Writing

All prose in this repository is **English**: documentation, code comments, commit messages, pull requests, issues and
release notes. Only the German pages of the website and the German interface texts are German.

Write for the reader: what they want to do, then how. Short sentences, one idea each. The `Prose` check runs
[Vale](https://vale.sh/) (rules in `.vale.ini` and `.vale/`) on `README.md`, `CONTRIBUTING.md`, `CLAUDE.md`, `docs/` and
the English website pages. German prose markers in an English document are an error and fail the check; filler words and
long sentences are warnings that are only reported. Run it locally with `scripts/vale_setup.sh`, then
`.vale/bin/vale $(scripts/vale_scope.sh)`.

## Dependencies and licences

Tesserarr is licensed under the GNU Affero General Public License v3.0 or later. Every dependency that ships in the image
must carry a licence the project can redistribute under it. One file decides: [`licence-policy.yml`](licence-policy.yml)
(JSON, which is valid YAML). It holds the allow list (`allow-licenses`) and the reviewed exceptions
(`x-licence-exceptions`).

The `Licence gate (composer, npm)` check judges the **whole** locked dependency tree, not only what a pull request
changes:

```bash
composer licenses --format=json --no-dev --locked > composer-licences.json
npx --yes license-checker-rseidelsohn@5.0.1 --production --excludePrivatePackages --json > npm-licences.json
php scripts/licence_gate.php composer-licences.json npm-licences.json licence-policy.yml
```

It prints every refused package with its licence and exits 1. A Composer licence list is a choice (one allowed entry is
enough); an npm expression follows SPDX (`OR` needs one allowed side, `AND` needs all). The same job runs `composer audit`
and `npm audit --omit=dev --audit-level=high`.

**A new dependency whose licence is on the list needs nothing.** If the gate refuses a package whose real licence is
acceptable (metadata that is wrong, ambiguous or missing), add an exception in the same pull request:

```json
{"purl": "pkg:npm/example@1.2.3", "licence": "MIT", "reason": "package.json has no licence field; the LICENSE file is the MIT text."}
```

The purl names the exact version (`pkg:composer/vendor/name@version` or `pkg:npm/name@version`), so an update is judged
again. `licence` is the SPDX expression of the licence file and has to pass the allow list itself.

A licence that is **not** on the list is not an exception to add quietly: it is a decision about what the project may
redistribute. Open an issue first. `GPL-2.0-only`, `LGPL-2.0-only` and SSPL are left out on purpose (incompatible with
AGPL-3.0), as is anything non-free.

### Dependency updates (Renovate)

[Renovate](https://docs.renovatebot.com/) keeps the dependencies current: Composer, npm (also the release toolchain in
`.github/release-tools/`), GitHub Actions (pinned by SHA), the base images in `docker/base-images.env` and the images
pinned by digest in the workflows and scripts (PostgreSQL, Playwright, Trivy). It runs self-hosted every night
(`.github/workflows/renovate.yml`) as its own GitHub App, which is no bypass actor of the rulesets, reads `renovate.json`
from `main` and opens pull requests against `main` and every `milestone/**` branch. Files shared with `main` (workflows, scripts, `docker/base-images.env`) are updated on `main` only
and reach a milestone with its sync pull request. Dependabot opens no version updates; Dependabot alerts stay on.

- **Commit messages** follow Conventional Commits: `chore(deps):` for updates that do not ship (development
  dependencies, lockfile updates, base images), `fix(deps):` for a security fix of a dependency in the image, which
  releases a patch, and `ci(deps):` for GitHub Actions and the release toolchain.
- **Waiting time.** An update is offered once the release is three days old (npm, Composer, GitHub Actions) or two days
  (Docker images); a security fix comes at once. A release without a timestamp is never treated as old enough. Docker Hub
  dates every tag by its last push, so a re-pushed tag starts the wait again for its digest update. GHCR and MCR give no
  timestamps; their updates are offered without a wait, but they only sit in owned files and never merge by themselves.
- **Automerge.** Renovate merges an update by itself once every check of the pull request is green, but only when it
  changes nothing but files without a code owner (`.github/CODEOWNERS`): `composer.lock`, the `package-lock.json` files
  and `docker/base-images.env`. `renovate.json` stays owned, because it decides what merges without review. That covers
  updates inside the declared range and patch, minor and digest updates of the base images. Lockfile maintenance
  (Mondays) refreshes the transitive versions and is merged by the maintainer: it has no release timestamps, so the
  waiting time cannot hold its new versions back. The required check `Lockfile sources` (`scripts/lockfile_sources.py`) keeps every locked download on its
  expected host: `https://registry.npmjs.org/` with a sha512 integrity for npm, GitHub or Packagist over https for
  Composer. Beyond the host, every locked package must equal its published metadata (npm registry, Packagist), be
  required from `package.json` or `composer.json`, and, on a pull request, not go down or change its major against the
  target branch unless the manifest changes as well. Versions are compared per package name, wherever the package
  sits in `node_modules`, and per major: within each major of the target branch the highest version may not go down
  and no new copy may sit below it, a major may only disappear for a higher one the branch already had, and a major
  it did not hold fails. For npm, the version each package loads for a dependency may not go down either, also for a
  package that moved to another place in `node_modules` (compared with the copies of the same package on the target
  branch); bundled copies inside another package's tarball are never an allowed version. Whether an entry is
  bundled follows from the tree (a package above it that bundles it), not from its `inBundle` flag, and the two must
  agree. A Composer package belongs in
  `packages` exactly when `require` needs it, and Composer prereleases are ordered like Composer orders them
  (`beta9` < `beta10` < `RC1`). A `--base` that is not a commit fails the check.
- **When `Lockfile sources` refuses a legitimate update.** The check fails closed on cases the project does not have
  today: an npm alias that only a dependency declares, a Composer metapackage, a `dev-*` version, a path repository
  declared with a glob, and an npm dedupe that lets a package load an older copy that already sat in the tree (still
  within its range, but lower than what it loaded on the target branch). Such a pull request needs the maintainer: confirm the change by hand (the package, its source
  and why it is needed), then extend `scripts/lockfile_sources.py` with a test for that case in the same pull request,
  instead of merging past a red check. An update that changes `composer.json`,
  `package.json`, a workflow, a GitHub Action or `docker/Dockerfile` waits for the maintainer's review, as does every
  major update.
- **Base images.** `docker/base-images.env` is the only place the base images are pinned (`NAME_IMAGE=repository:tag@sha256:<digest>`);
  `docker/Dockerfile` declares them as `ARG` without a default. The check `Base image pins` (`scripts/base_images.py
  check`, a required check) fails when the two differ, and when a pin names an image that is not on the allowlist in
  `scripts/base_images.py`: without a code owner on the pin file, only a tag or a digest may change unreviewed. On a
  pull request a pin may also not go down against the target branch or change its flavour (`-php8.5-trixie`), and
  every pin that changes must carry the digest Docker Hub serves for its tag at that moment.

## Licence headers

<!-- REUSE-IgnoreStart -->

Every file the project owns starts with two SPDX lines in its own comment syntax:

```text
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
```

Put them at the top, after `<?php`, a shebang (`#!`) or a `<!doctype html>` line if the file has one:

```php
<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);
```

Every PHP file declares strict types, after the SPDX lines where it has them. Pint enforces it (`declare_strict_types`
in `pint.json`).

```yaml
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
```

The `#` form applies to shell, YAML, TOML, INI, `Dockerfile`, `Caddyfile` and `.gitignore`; the `//` form to TypeScript and
JavaScript; Markdown and HTML use an HTML comment, Svelte components too, CSS uses `/* … */`.

- The year is the year the file was created. It is not bumped when the file changes.
- You may add your own line `SPDX-FileCopyrightText: <year> <your name>` below the first one. This is voluntary; the
  [CLA](CLA.md) stays as it is.
- Files that cannot carry a comment (JSON, lockfiles, images) and third-party files are covered by
  [`REUSE.toml`](REUSE.toml). A new entry there needs a comment that says why. Third-party files keep their own licence and
  copyright: the files taken over from the Laravel Svelte starter kit and the shadcn-svelte components stay MIT.
- `packages/laravel-jmap-client/` is licensed under **MIT**, not AGPL, so the package can be used in projects under any
  licence. Its files carry `SPDX-License-Identifier: MIT`; the package has its own `LICENSE`.
- `reuse lint` must pass (CI job `REUSE — licensing of every file`, locally
  `docker run --rm -v "$PWD":/data fsfe/reuse:6.3.0-debian lint`).

<!-- REUSE-IgnoreEnd -->

## Milestones, branches and releases

Patch releases come from `main`. Minor and major releases come from a **milestone**: an integration branch
`milestone/X.Y.0` that collects the features of version `X.Y.0` and is merged into `main` as one pull request. Releases are
made from `main` only; a milestone ships by merging `milestone/X.Y.0` into `main`, never from the milestone branch itself.

| Change                                         | Branch from                         | Pull request targets          | Merge style      | Released as               |
| ---------------------------------------------- | ----------------------------------- | ----------------------------- | ---------------- | ------------------------- |
| Feature (`feat`)                               | `milestone/X.Y.0`                   | `milestone/X.Y.0`             | squash           | part of `X.Y.0`           |
| Breaking change (`!`, `BREAKING CHANGE:`)      | `milestone/X.0.0`                   | `milestone/X.0.0`             | squash           | part of `X.0.0`           |
| Bug or security fix (`fix`, `perf`)            | `main`                              | `main`                        | squash           | next patch release        |
| Docs, CI, chore, refactor, tests               | `main` (or the milestone it belongs to) | same                      | squash           | not released on its own   |
| Milestone                                      | (none)                              | `milestone/X.Y.0` into `main` | **merge commit** | exactly `X.Y.0`           |
| Patch milestone                                | (none)                              | `milestone/X.Y.x` into `main` | **merge commit** | next free patch `X.Y.Z`   |
| Sync after a fix                               | (none)                              | `main` into `milestone/X.Y.0` | **merge commit** | (nothing)                 |

**Starting a milestone.** A maintainer opens the GitHub milestone `X.Y.0` and creates `milestone/X.Y.0` from `main`. CI
runs for pull requests into `milestone/**` exactly as for `main`. Major versions only come through a milestone `X.0.0`.
The first release of the project is `0.1.0`. The repository carries the tag `v0.0.0` on the initial commit as the
starting point: without it, semantic-release computes `1.0.0` for the first `feat`, and the release guard stops the run.
A maintainer sets it once:

```bash
git tag -a v0.0.0 85c6f9bb0a560e4f957451260de09f8c8875e140 -m "v0.0.0: baseline before the first release"
git push origin v0.0.0
```

**One branch and one pull request per task.** Each task of a milestone gets its own branch from the milestone branch and
one pull request against it, squash-merged after review.

**Workflow and repository files go to `main` first.** GitHub runs `schedule` and `workflow_dispatch` only from workflow
files on the default branch, and Renovate and Dependabot read their configuration only there. So workflows,
`renovate.json`, `.github/dependabot.yml`, `docker/base-images.env`, CODEOWNERS, the CLA, the licence policy and the
scripts they call come in a separate `chore:` or `ci:` pull request into
`main`; application code comes through the milestone branch. Until the milestone is merged, the jobs that need the
application pass on `main` as an explicitly named no-op; on every other branch a missing `composer.json` or
`package.json` fails them.

**Every issue has a release.** An issue gets a milestone when it is opened: a feature the minor milestone `X.Y.0` it ships
in, a fix the patch milestone `X.Y.x`. The label `status:implemented` marks an issue whose work is done, reviewed and on
the milestone branch; it closes when the milestone pull request is merged (`Closes #N`).

**Shipping a milestone.** Open one pull request `milestone/X.Y.0` into `main` and merge it with a **merge commit**, never
squash or rebase. GitHub then writes the standard subject `Merge pull request #N from strausmann/milestone/X.Y.Z`, which is
how the release guard recognises a milestone release. Do not change that subject (no custom subject when merging). A merge
commit keeps every feature commit, so semantic-release computes exactly `X.Y.0` and the changelog lists each entry.

**The milestone is empty before its release.** A release that merges a milestone needs the GitHub milestone to have no
open issue: close them, move them to another milestone with a reason, or have a maintainer waive one with the label
`release:waived-by-maintainer`. After the release, close the GitHub milestone.

**Patch milestones `X.Y.x`.** A group of fixes for a release line is collected in a patch milestone titled `X.Y.x` (branch
`milestone/X.Y.x`). The title covers every patch `X.Y.Z` with `Z >= 1`: the milestone pull request gets the next free patch
number when it is merged, because dependency updates, weekly rebuilds and single fixes may already have released patches
on the way. Such a milestone takes only `fix`, `perf`, `refactor`, `test`, `docs`, `ci`, `chore` and `build` commits.
Renovate pull requests and single fixes go to `main` at any time.

**Fixes while a milestone is open.** Fixes go to `main` and ship as patch releases. After every patch release from `main`,
merge `main` back into each open milestone branch with a sync pull request (`chore: sync main into milestone/X.Y.0`) and a
merge commit.

**Two guards keep the numbering honest.** The `release-impact` check classifies every pull request into `main`: from a
branch other than `milestone/*` only patch-level or non-releasing changes pass. The release guard runs inside the release
and stops it when the computed version is neither the next patch nor covered by an open milestone, or when a minor version
did not come in through the milestone merge.

## Releases

Releases are scheduled, not made on every merge. The `Release` workflow runs nightly at 04:00 UTC and can be started
early from the Actions tab (with `dry_run` to see the computed version without publishing anything). semantic-release
reads the Conventional Commits on `main` since the last tag, computes the next version, checks it against the release
guard, writes the changelog and tags `v<version>`. Maintainers do not tag by hand.

**Draft first, publish last.** semantic-release creates the GitHub release as a draft. The images are then built from the
tag, the SBOM is attached to the draft, and only then is the draft published. A failed image or SBOM job leaves a draft
and a step summary with the rerun command, never a published release with missing assets.

**Images.** Every release is published as one multi-arch image (`linux/amd64`, `linux/arm64`) to both registries,
`ghcr.io/strausmann/tesserarr` and `docker.io/bstrausmann/tesserarr`, with the same tags:

| Tag          | Built from                         | Example      |
| ------------ | ---------------------------------- | ------------ |
| `x.y.z`      | a release                          | `0.1.0`      |
| `x.y`, `x`   | a stable release                   | `0.1`, `0`   |
| `latest`     | the newest stable release          | `latest`     |
| `dev`        | every push to `main`               | `dev`        |
| `x.y.z-rc.N` | a preview of a milestone           | `0.1.0-rc.1` |

A preview gets only its exact tag, never `x.y`, `x` or `latest`. Each image carries build provenance (`mode=max`) and an
SBOM; the CycloneDX SBOM is also attached to the GitHub release. The image index is signed without a key (cosign with the
workflow's OIDC token), from `main` and `milestone/*` only:

```bash
cosign verify docker.io/bstrausmann/tesserarr:0.1.0-rc.1 \
  --certificate-identity-regexp 'https://github.com/strausmann/tesserarr/.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

This needs **cosign v3 or newer**. cosign v3 stores the signature as a Sigstore bundle in the OCI referrers of the image;
cosign v2 does not look there and reports `no signatures found`. Without a local installation, run cosign in a container:

```bash
docker run --rm ghcr.io/sigstore/cosign/cosign:v3.0.2 verify docker.io/bstrausmann/tesserarr:0.1.0-rc.1 \
  --certificate-identity-regexp 'https://github.com/strausmann/tesserarr/.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

**Previews.** Before a milestone ships, a maintainer runs `Preview image` on the milestone branch with the milestone
version (`0.1.0`, or `0.1.x` for the next free patch); any other branch is refused, because only `main` and the
milestone branches are signed. The rc number counts up from the tags in GHCR. A preview is signed like a release image
(the signature covers the image index with its SBOM and provenance attestations), and gets no
git tag, no release and no changelog entry. `Registry cleanup` removes a preview on both registries once its release
exists; it deletes only when the repository variable `GHCR_CLEANUP_ENABLED` is `true`, a manual run is a dry run.

**Weekly rebuild.** `Image rebuild` (Mondays, and by hand) rebuilds the highest published release from its tag, with the
base image pins of `main` and freshly upgraded Debian packages, and scans the rebuild and the published image with the
same Trivy and the same database as the CI report (`scripts/trivy_report.py`). When the rebuild has no more critical and
no more high findings than the published image and fewer of both together, it pushes the empty commit
`fix(image): rebuild with updated base packages` to `main` with the deploy key and starts `Release`: semantic-release
computes the next patch, which the release guard allows, and the release is built, signed and published like any
other, with its SBOM and the Docker Hub description. A commit instead of a release outside semantic-release keeps one
way to release: the changelog lists the rebuild, the tag comes from semantic-release, and the release guard decides.
Critical findings without a fixed version open one issue labelled `type:security`, or are added to the open one; a
finding already listed changes nothing. When the vulnerability database cannot be downloaded, nothing is compared,
released or opened. Before the first release (only `v0.0.0`) the workflow ends with a notice.

**Docker Hub page.** `Docker Hub description` writes `docs/dockerhub/README.md` and the short description to Docker Hub
when the file changes on `main` and after every release. The Docker Hub page is public, so that file follows the same
rule as the rest of the repository: no details of a real installation.

**Repository settings the release needs:**

| Name                         | Kind     | Used by                                              |
| ---------------------------- | -------- | ---------------------------------------------------- |
| `SEMANTIC_RELEASE_SSH_KEY`   | secret   | `Release`: deploy key with write access (ruleset bypass) to push the tag and the changelog; `Image rebuild`: to push the rebuild commit |
| `DOCKERHUB_USERNAME`         | secret   | `Docker — publish`, `Preview image`: the organization name `bstrausmann`, the login of the organization access token |
| `DOCKERHUB_TOKEN`            | secret   | the same jobs: organization access token with Image Push on `bstrausmann/tesserarr` |
| `DOCKERHUB_ADMIN_USERNAME`   | secret   | `Docker Hub description`, `Registry cleanup`: a personal account that is a member or owner of the organization (not the organization) |
| `DOCKERHUB_TOKEN_ADMIN`      | secret   | the same jobs: personal access token of that account, permission Read, Write, Delete. Organization access tokens cannot edit repository metadata (peter-evans/dockerhub-description#294) |
| `ARM64_NATIVE_RUNNER`        | variable | `true` builds arm64 on the native runner (public repositories only); otherwise QEMU |
| `GHCR_CLEANUP_ENABLED`       | variable | `true` lets the weekly `Registry cleanup` delete     |
| `RENOVATE_APP_ID`            | secret   | `Renovate`: ID of the Renovate GitHub App, installed on this repository only |
| `RENOVATE_APP_PRIVATE_KEY`   | secret   | `Renovate`: a private key of that app; each run makes an installation token for this repository from it |

## Trademarks

Sonarr, Radarr, Prowlarr, Plex, Syncthing and other names are trademarks of their owners. Tesserarr is an independent
project; do not add wording that suggests endorsement by, partnership with or affiliation to any of them.
