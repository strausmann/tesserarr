# Contributing

## No environment details — ever

This repository must never contain anything that identifies a real
installation. Not in code, not in issues, not in commit messages, not in pull
requests, not in documentation, and not in examples.

That includes host names, domains, IP addresses, user names, account
identifiers, share names, file system paths from a real machine, tokens, and
screenshots that show any of the above.

Use placeholders instead:

| Instead of a real value | Write |
|---|---|
| a host name | `media.example.org` |
| a path | `/media/movies`, `/syncthing/outgoing` |
| a user | `alice`, `bob` |
| a token | `<token>` |

The reason is simple: this project is about handing out access to a private
library. Anything that maps a configuration to a real person or machine makes
that person easier to target.

## Language

- **Code is always English** — identifiers, function names, struct fields, CLI
  flags, configuration keys, JSON and YAML field names, file names, test
  function names. No exceptions.
- **Prose may be German** — comments, commit messages, issue text, the text
  inside `t.Run("…")`, operator-facing error messages. Use real umlauts
  (ä ö ü ß), never `ae`/`oe`/`ue`/`ss`.

## Commits

[Conventional Commits](https://www.conventionalcommits.org/). Subject line
lowercase after the colon. Releases are derived from commit messages, so the
type matters:

```
feat(indexer): newznab-suche liefert nur freigegebene bibliotheken
fix(provision): hardlink faellt bei fremdem dateisystem auf kopie zurueck
docs(adr): adr-0007 zur karenzzeit
```

## Tooling

Repository tooling (commit-message linting, and anything else outside `web/`,
which has its own `package.json`) is installed with `pnpm`, not `npm` — this
matches the package manager the web interface's Dockerfile uses
(`corepack enable` + `pnpm install --frozen-lockfile`).

```
pnpm install
```

If `pnpm` is missing, get it via corepack (bundled with Node 22):

```
corepack enable
```

If that fails with a permission error — `corepack enable` without arguments
tries to write into a system directory such as `/usr/bin` — install into a
user directory instead:

```
corepack enable --install-directory "$HOME/.local/bin"
export PATH="$HOME/.local/bin:$PATH"
```

After `pnpm install` runs, `git config core.hooksPath` should print
`.husky/_` — that means the commit-message hook is active.

## Before you open a pull request

- `go build ./...`, `go vet ./...`, `go test ./... -race`
- `gofmt -l .` is empty
- the linter reports nothing
- the coverage gate passes
- the interface check passes — it renders the pages in a real browser and
  fails on console errors, failed requests or horizontal scrolling

A pull request that changes behaviour without a test does not get merged.

## Architecture decisions

Anything that shapes the architecture, the technology choice or the way the
service is operated gets an ADR under [`docs/adr/`](docs/adr/) — including the
alternatives that were rejected and why. Copy an existing one as a template and
add your entry to the registry in the same change.

Trivia, formatting and obvious implementation details do not need an ADR.
