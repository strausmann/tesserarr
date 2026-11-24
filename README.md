<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Tesserarr

[![License: AGPL-3.0-or-later](https://img.shields.io/badge/License-AGPL--3.0--or--later-blue.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-early%20development-orange.svg)](docs/adr/)
[![Go](https://img.shields.io/badge/Go-1.25-00ADD8.svg?logo=go&logoColor=white)](https://go.dev)

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

## Documentation

Setup, operations and the full picture of how Syncthing, Prowlarr, Sonarr,
Radarr and your media server fit together will live on the project's
documentation site.

## License

Tesserarr is licensed under the GNU Affero General Public License v3.0 or later
(AGPL-3.0-or-later), see [LICENSE](LICENSE). Contributions need a signed
[Contributor License Agreement](CLA.md), see [CONTRIBUTING.md](CONTRIBUTING.md).
The JMAP client in `packages/laravel-jmap-client/` (`strausmann/laravel-jmap-client`) is licensed under MIT.
