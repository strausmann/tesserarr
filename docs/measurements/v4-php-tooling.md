<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# V4: PHP tooling compared with the Labelyard gates

This page records, per gate, what it took to set up the PHP counterpart of a Labelyard gate and what it costs to run.
It feeds the decision on a Laravel project template.

- **Setup time**: hours of work for the gate, including the counter-test.
- **CI runtime**: median of three runs at the same commit, with the commit. Filled in from the first CI runs after the
  pull requests are pushed; until then the column holds the local runtime, marked *(local)*.
- **False alarms until green**: runs that failed without a defect in the change under test.

Local runtimes were taken on a 4-core development VM, with PHP in the image's `test` stage and PostgreSQL 17.11 in a
container.

| Gate (job) | Labelyard counterpart | Setup time (h) | CI runtime (s) | Commit | False alarms until green | Difference to Labelyard |
|---|---|---|---|---|---|---|
| `PHP — lint, static analysis, test`: Pint | Ruff lint and format | 0.1 | 9 *(local)* | pending | 0 | Kit preset (`laravel`) used as is |
| `PHP — lint, static analysis, test`: Larastan level 8 | Mypy strict | 0.3 | 16 *(local)* | pending | 0 | Raising level 7 to 8 found 10 nullable `$request->user()` calls in the kit controllers; fixed with `#[CurrentUser]` |
| `PHP — lint, static analysis, test`: Pest with coverage | pytest `--cov-fail-under=80` | 0.2 | 32 *(local)* | pending | 0 | Codecov left out; total 87.6 % at the start |
| `PHP — lint, static analysis, test`: coverage gate per directory | (none) | 0.8 | < 1 *(local)* | pending | 0 | Own script; starts with no path, each task adds its directory |
| `PHP — lint, static analysis, test`: migrations, nothing pending | Alembic `check` | 0.1 | 2 *(local)* | pending | 0 | `migrate --pretend` must print "Nothing to migrate" |
| `Licence gate (composer, npm)` | Dependency review + licence gate | 1.5 | 25 *(local)* | pending | 0 | Judges the whole locked tree (232 packages), not a diff; own script, same allow list |
| `Semgrep` | CodeQL | 0.6 | 17–67 *(local, first run pulls the rules)* | pending | 0 | CodeQL needs a Code Security licence on a private repository and has no PHP. `p/php` alone missed `eval($_GET[...])` in the counter-test; `r/php.lang.security` and `p/phpcs-security-audit` added. 0 findings at the start |
| `Privacy / secret scan` | Privacy / secret scan | 0.5 | 9 *(local, gitleaks)* | pending | 1 | Adds e-mail addresses and gitleaks over the branch history; the test fixtures moved from `10.0.0.x` to the documentation range |
| `REUSE — licensing of every file` | REUSE lint | 1.5 | 4 *(local)* | pending | 0 | Kit files stay MIT and are listed one by one in `REUSE.toml` |
| `Prose` | Vale (`prose.yml`) | 0.4 | < 1 *(local)* | pending | 0 | No glossary yet; the error rule is German prose in English documents |
| `commitlint` | commitlint | 0.2 | 3 *(local)* | pending | 0 | Same rules; config copied next to the installed packages |
| `Web — typecheck, lint, test, build`: svelte-check and tsc | `tsc --noEmit` | 0.2 | 22 *(local)* | pending | 0 | `--fail-on-warnings`; a second `tsconfig.node.json` type-checks `e2e/` and the test configs, which the kit's `tsconfig.json` leaves out |
| `Web — typecheck, lint, test, build`: Oxlint and formatter | ESLint and Prettier | 0.3 | 6 *(local)* | pending | 0 | Kit tool chain (Vite+ `vp lint`, `vp fmt`) kept; the formatter is limited to code, because it would rewrite the hand-formatted Markdown, YAML and TOML of task 2 |
| `Web — typecheck, lint, test, build`: Vitest with coverage | Vitest with coverage | 0.5 | 8 *(local)* | pending | 0 | `resources/js/lib` at 80 % (92.7 % lines, 80.4 % branches at the start); the component minimum starts with the first Tesserarr component |
| `Web — typecheck, lint, test, build`: Playwright UI gate, artboards, keyboard | Playwright E2E, `short-viewport.spec.ts` | 3.0 | 108 *(local, median of 3 runs of `scripts/e2e/run.sh`, 25 tests)* + 126 *(local, cold image build)* | pending | 4 | Runs against the built image (stage `e2e`) with PostgreSQL, not a dev server; adds axe, both themes and artboard checks, which Labelyard does not have. False alarms while building the gate: the browser's own `outline: auto` ring, an artboard control hidden behind the phone menu, a PostgreSQL readiness race in the runner, and font requests of the sign-in redirect aborted by the next navigation (seen in Firefox). Firefox is left out for now: 3.1 min instead of 1.5 |
| `Docker — build smoke test` | Docker build smoke test | 0.2 | pending | pending | pending | Reuses the smoke scripts of task 1; no SBOM yet |

## Known sources of false alarms

- **Semgrep rule packs are not pinned.** `semgrep.yml` loads `p/php`, `r/php.lang.security`, `p/phpcs-security-audit`,
  `p/javascript`, `p/typescript` and `p/secrets` from the registry at run time; only the Semgrep engine is pinned (image
  digest). A new or changed upstream rule can turn the required check red without a change in this repository. Each such
  run is counted as a false alarm in the table above. If they become frequent, the rules are vendored into the repository
  at a fixed revision and loaded from there.
