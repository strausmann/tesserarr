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

Docker is the only requirement; PHP 8.4, Composer, Node and PostgreSQL run in containers.

```bash
cp .env.example .env
docker compose -f compose.dev.yaml build
docker compose -f compose.dev.yaml run --rm --no-deps web artisan key:generate --show   # put the key into .env as APP_KEY
docker compose -f compose.dev.yaml up
```

`up` first runs the one-off `migrate` service (`artisan migrate --force`); `web`, `worker` and `scheduler` start only
after it has finished. A satellite never migrates: it has no database. The web interface is then on
<http://localhost:8080>.

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
docker build -f docker/Dockerfile --target test .   # the test stage of the image
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
./vendor/bin/pint --test                       # formatting (Laravel preset)
./vendor/bin/phpstan analyse                   # Larastan, level 8, no baseline
composer audit --locked --abandoned=fail
./vendor/bin/pest --coverage --min=80 --coverage-clover=clover.xml
php scripts/coverage_gate.php clover.xml       # per-directory minimums, as listed in .github/workflows/ci.yml
npm run types:check                            # svelte-check
```

| Check                               | Job in CI                                  |
| ----------------------------------- | ------------------------------------------ |
| Pint, Larastan, audit, migrations, Pest, coverage gate | `PHP — lint, static analysis, test` |
| svelte-check, lint, tests, build    | `Web — typecheck, lint, test, build`       |
| Image build and smoke tests         | `Docker — build smoke test`                |
| Licences of every dependency        | `Licence gate (composer, npm)`             |
| Static analysis for security bugs   | `Semgrep`                                  |
| Private network artefacts, secrets  | `Privacy / secret scan`                    |
| SPDX header or annotation per file  | `REUSE — licensing of every file`          |
| Vale on the English docs            | `Prose`                                    |
| Pull request title                  | `commitlint`                               |

**Coverage.** The whole application needs 80 %. Security-relevant directories need more: `app/Authorization`,
`app/Invitations`, `app/Mfa`, `app/Identity` and `app/Tokens` 90 %; `app/Execution`, `app/Catalog`, `app/Satellites` and
`app/Connections` 85 %. `scripts/coverage_gate.php` checks a directory only once it exists, so the pull request that
creates one of these directories adds its `<path>:<min>` to the coverage gate step in `.github/workflows/ci.yml`. A
directory that exists but has no lines in the report fails.

**Mutation tests** run nightly (`pest --mutate --min=70`) on the directories that carry authorisation, invitations,
second factors, execution and the catalogue.

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
```

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
- `packages/laravel-jmap-mailer/` is licensed under **MIT**, not AGPL, so the package can be used in projects under any
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
The first release of the project is `0.1.0`; the repository carries the tag `v0.0.0` as the starting point.

**One branch and one pull request per task.** Each task of a milestone gets its own branch from the milestone branch and
one pull request against it, squash-merged after review.

**Workflow and repository files go to `main` first.** GitHub runs `schedule` and `workflow_dispatch` only from workflow
files on the default branch, and Dependabot reads its configuration only there. So workflows, `.github/dependabot.yml`,
CODEOWNERS, the CLA, the licence policy and the scripts they call come in a separate `chore:` or `ci:` pull request into
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
number when it is merged, because Dependabot updates and single fixes may already have released patches on the way. Such
a milestone takes only `fix`, `perf`, `refactor`, `test`, `docs`, `ci`, `chore` and `build` commits. Dependabot pull
requests and single fixes go to `main` at any time.

**Fixes while a milestone is open.** Fixes go to `main` and ship as patch releases. After every patch release from `main`,
merge `main` back into each open milestone branch with a sync pull request (`chore: sync main into milestone/X.Y.0`) and a
merge commit.

**Two guards keep the numbering honest.** The `release-impact` check classifies every pull request into `main`: from a
branch other than `milestone/*` only patch-level or non-releasing changes pass. The release guard runs inside the release
and stops it when the computed version is neither the next patch nor covered by an open milestone, or when a minor version
did not come in through the milestone merge.

## Releases

Releases are scheduled, not made on every merge. semantic-release reads the Conventional Commits on `main` since the last
tag, computes the next version, checks it against the release guard, writes the changelog, tags `v<version>` and publishes
the GitHub release and the images. Maintainers do not tag by hand; a release can be started early from the Actions tab.
Before a milestone ships, a maintainer can build a preview image `X.Y.0-rc.N` from the milestone branch; a preview gets
no tag, no release and no changelog entry.

## Trademarks

Sonarr, Radarr, Prowlarr, Plex, Syncthing and other names are trademarks of their owners. Tesserarr is an independent
project; do not add wording that suggests endorsement by, partnership with or affiliation to any of them.
