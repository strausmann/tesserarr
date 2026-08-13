# Tesserarr

[![test](https://github.com/strausmann/tesserarr/actions/workflows/test.yml/badge.svg)](https://github.com/strausmann/tesserarr/actions/workflows/test.yml)
[![release](https://img.shields.io/github/v/release/strausmann/tesserarr)](https://github.com/strausmann/tesserarr/releases)
[![license](https://img.shields.io/github/license/strausmann/tesserarr)](LICENSE)
[![image size](https://ghcr-badge.egpl.dev/strausmann/tesserarr/size)](https://github.com/strausmann/tesserarr/pkgs/container/tesserarr)
[![Status](https://img.shields.io/badge/status-early%20development-orange.svg)](docs/adr/)
[![Go](https://img.shields.io/badge/Go-1.26-00ADD8.svg?logo=go&logoColor=white)](https://go.dev)

Share parts of your media library with friends over an existing Syncthing link —
without torrents, without a public index, and without doing it by hand.

Your friends browse what you chose to share, request a title, and their own
Sonarr or Radarr picks it up as if it came from any other source. You decide who
sees which library, how much they may request, and how long a file stays around
if nobody collects it.

## Why the name

A *tessera hospitalis* was a small tablet that two befriended Roman households
broke in half, each keeping one piece. Presenting the matching half proved the
bond of guest-friendship — sometimes generations later. Two homelabs, one
invitation, a private tie.

The `-arr` suffix places the project in the family around Sonarr, Radarr and
Prowlarr. It is a genre marker, not a dependency: Tesserarr works fine without
any of them.

## How it works

```
FRIEND                             YOU
Prowlarr  ── search ──────────▶  indexer
Sonarr    ── grab ────────────▶  download client
          ◀─ progress, done ──      │
                                    ▼
                            hardlink into outgoing/
                                    │
                              Syncthing carries it
                                    │
income/ ◀───────────────────────────┘
Sonarr imports and renames
        │
deletes it ──────────────────▶  transfer counts as collected
```

Progress is measured, not guessed: Tesserarr reads it from its own Syncthing
instance, per device and folder.

## Status

Early development. Nothing is usable yet. The architecture decisions are
written down in [`docs/adr/`](docs/adr/) — start there if you want to know why
things are built the way they are.

## Development

Requires **Go 1.26 or newer**. Go fetches the matching toolchain itself since
1.21 (`GOTOOLCHAIN=auto`, the built-in default) — that needs network access to
`proxy.golang.org`. Without that access, or when `GOTOOLCHAIN=local` is set,
`go1.26.6` has to be installed by hand.

See [CONTRIBUTING.md](CONTRIBUTING.md) for the full workflow.

## Documentation

Setup, operations and the full picture of how Syncthing, Prowlarr, Sonarr,
Radarr and your media server fit together will live on the project's
documentation site.

## License

MIT — see [LICENSE](LICENSE).
