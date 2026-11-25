<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# CLAUDE.md: AI assistant configuration

This file configures **Claude Code** (and other LLM-based tools that read it) for this repository. It is short on purpose;
the project knowledge lives in the documents below.

## Read these first

1. [`README.md`](README.md): what Tesserarr is, the two roles, how to run it
2. [`CONTRIBUTING.md`](CONTRIBUTING.md): TDD, Conventional Commits, local checks, licences, branches and releases
3. [`docs/decisions/`](docs/decisions/): the decisions and why they were made
4. [`licence-policy.yml`](licence-policy.yml): which dependency licences the project may ship

If you are about to make a non-trivial change without having read those, stop and read them.

## Hard rules (no exceptions)

| Rule | Reference |
|---|---|
| **English prose** in everything committed: docs, comments, commit messages, pull requests, issues. Only the German website pages and interface texts are German | [CONTRIBUTING.md](CONTRIBUTING.md#writing) |
| **No real data**: no host names, domains, IP addresses, user names, account identifiers, paths or tokens of a real installation. Use `example.org`, `192.0.2.0/24`, `alice` | [CONTRIBUTING.md](CONTRIBUTING.md#no-environment-details-ever) |
| **No AI attribution**: no `Co-Authored-By` trailer for an assistant, no session links, no "generated with" footer in commits or pull requests | this file |
| **Commit author** `projects@strausmann.ch` | this file |
| **Never `git add -A` or `git add .`**: stage explicit paths and read `git status --short` before each commit | this file |
| **Conventional Commits** for every commit and pull request title; semantic-release depends on it | [CONTRIBUTING.md](CONTRIBUTING.md#5-commit-messages-conventional-commits) |
| **TDD**: failing test first, then code, then the counter-test | [CONTRIBUTING.md](CONTRIBUTING.md#4-make-changes-with-tdd) |
| **The satellite has no database and no queue.** Code that runs in the satellite role never opens a database connection, never dispatches to a queue and never touches the session | [README.md](README.md#roles) |
| **Worker-safe code**: no state in `static`, `scoped()` instead of `singleton()` for anything request-bound, no public writable properties on services, no runtime `config()` writes, no `App::setLocale()` outside a middleware, no `View::share()`, no `exit`/`die`. Pages with user data get an `assertNoStateLeakBetweenUsers()` case | [CONTRIBUTING.md](CONTRIBUTING.md#worker-safe-code) |
| **`declare(strict_types=1);`** in every PHP file, after the SPDX lines | [CONTRIBUTING.md](CONTRIBUTING.md#licence-headers) |
| **One authorisation function.** Every permission decision goes through `App\Authorization\Authorizer`; no `Gate::allows`, `$user->can()` shortcut or role check of its own in controllers | [`docs/decisions/`](docs/decisions/) |
| **Licence headers**: every new file carries the SPDX lines, or a `REUSE.toml` entry with a reason | [CONTRIBUTING.md](CONTRIBUTING.md#licence-headers) |

## Branches, pull request targets and merge style (mandatory)

- **Features (`feat`) and breaking changes** branch from `milestone/X.Y.0` (or `milestone/X.0.0`); the pull request
  targets that milestone branch. Never put a `feat`, `!` or `BREAKING CHANGE:` commit on `main` outside a milestone merge.
- **Each task** of a milestone has its own branch and one pull request against the milestone branch, squash-merged.
- **Fixes, docs, CI, chores** branch from `main` and target `main`. Workflow and repository files go to `main` in their
  own pull request first (see CONTRIBUTING.md).
- **Milestone pull requests** (`milestone/X.Y.0` into `main`) and **sync pull requests** (`main` into the milestone) are
  merged with a **merge commit**. Never squash or rebase them.
- A milestone pull request keeps the standard subject GitHub writes, which the release guard recognises:
  `Merge pull request #N from strausmann/milestone/X.Y.Z`. Never change that subject. Sync pull requests have no
  required subject.
- Releases happen from `main` only. Never tag, release or publish from a milestone branch.

## Things you should propose, not do silently

- A new dependency: say why in the pull request description, and check its licence against `licence-policy.yml`.
- A new API endpoint: it comes with its OpenAPI export (`website/openapi/openapi.json`) and its documentation page in
  English and German.
- A new file over about 400 lines: ask whether it should be split.
- An architecture change: propose it as a decision record under `docs/decisions/` before implementing.
- Weakening a gate (a lower threshold, `|| true`, `continue-on-error`, a skipped test): never. Fix the cause or ask.

## Where to put new things

| Type of work | Location |
|---|---|
| Runtime role and boot | `app/Runtime/` |
| Permissions, roles, the `Authorizer` | `app/Authorization/` |
| Audit log | `app/Audit/` |
| Identities and login methods (OIDC, Plex, header) | `app/Identity/` |
| Locks and race barriers | `app/Concurrency/` |
| Invitations | `app/Invitations/` |
| Second factors and MFA policy | `app/Mfa/` |
| API tokens | `app/Tokens/` |
| Satellites and their jobs | `app/Satellites/` |
| Media shares, path resolution | `app/MediaShares/`, `app/Execution/` |
| Connections to Sonarr, Radarr, Prowlarr, Plex, Syncthing | `app/Connections/` |
| Catalogue and grants | `app/Catalog/` |
| Folder index | `app/Indexing/` |
| Encryption and redaction of personal data | `app/Privacy/` |
| Mail (PGP/MIME) | `app/Mail/`; the JMAP client is the MIT package `packages/laravel-jmap-client/` (`Strausmann\JmapClient\`) |
| Live updates | `app/Broadcasting/` |
| Console commands (`tesserarr:*`) | `app/Console/Commands/` |
| Routes | `routes/web.php` (session), `routes/api_app.php` (`/api/v1/app`), `routes/api.php` (`/api/v1`, token) |
| Interface | `resources/js/pages/`, `components/`, `layouts/`, `lib/` |
| Image, entrypoint, Caddyfile | `docker/` |
| User documentation (English and German) | `website/` |
| Decisions, designs, Docker Hub text, measurements | `docs/decisions/`, `docs/designs/`, `docs/dockerhub/`, `docs/measurements/` |
| CI helper scripts | `scripts/` |
| Tests | `tests/Unit/`, `tests/Feature/`, `tests/Concurrency/`, `tests/Contract/`; browser tests in `e2e/` |
| Two-user tests through one Octane worker | `tests/Worker/` |
| PHPStan rules of the project and their tests | `tests/PHPStan/` (configuration `tests/PHPStan/worker-safety.neon`) |

## When you don't know

Ask in the pull request description or open a draft pull request with the questions. Do not invent values, especially
library APIs, endpoints of other services or deployment details. Endpoints of other services are checked against their
official documentation, with the URL in a code comment.

## Peer review with other AI assistants

Every pull request is reviewed by **Codex** (`chatgpt-codex-connector`; comment `@codex review` to request a new review
after changes). **GitHub Copilot** can be requested on a pull request.

- Read their comments carefully; they often catch real issues.
- Reply to every comment: fix it, or explain why not.
- Treat them as colleagues, not gates. The maintainer decides.

## Privacy reminder

You may be invoked from contexts that can see the maintainer's private repositories and notes. **Never** copy values from
those contexts into this repository, its issues or its pull requests. Use placeholders. The `Privacy / secret scan` job
rejects obvious leaks; your judgement is the first line of defence.
