# Tesserarr

[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-early%20development-orange.svg)](docs/adr/)
[![PHP](https://img.shields.io/badge/PHP-8.4-777BB4.svg?logo=php&logoColor=white)](https://www.php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13-FF2D20.svg?logo=laravel&logoColor=white)](https://laravel.com)

Share parts of your media library with friends over an existing Syncthing link —
without torrents, without a public index, and without doing it by hand.

Your friends browse what you chose to share, request a title, and their own
Sonarr or Radarr picks it up as if it came from any other source. You decide who
sees which library, how much they may request, and how long a file stays around
if nobody collects it.

## Why the name

A _tessera hospitalis_ was a small tablet that two befriended Roman households
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

## Roles

One image, two kinds of installation, chosen with `TESSERARR_ROLE`:

| Role                | What it is                                                                   |
| ------------------- | ---------------------------------------------------------------------------- |
| `central` (default) | Users, invitations, catalogue and the web interface, backed by PostgreSQL    |
| `satellite`         | Runs jobs next to a media share; no database, no web interface besides `/up` |

Any other value stops the start. Each container runs one process, given as the
command:

| Command     | Role      | Process                              |
| ----------- | --------- | ------------------------------------ |
| `web`       | central   | web server on port 8080 (FrankenPHP) |
| `worker`    | central   | queue worker                         |
| `scheduler` | central   | task scheduler                       |
| `reverb`    | central   | WebSocket server for live updates    |
| `satellite` | satellite | satellite server on port 8080        |
| `artisan …` | either    | a console command                    |

`/up` answers 200 when the instance is healthy; the central role also checks
its database there.

## Development

Requires Docker; PHP, Composer and PostgreSQL run in containers.

```sh
cp .env.example .env
docker compose -f compose.dev.yaml build
docker compose -f compose.dev.yaml run --rm --no-deps web artisan key:generate --show   # put the key into .env as APP_KEY
docker compose -f compose.dev.yaml up
```

`up` first runs the one-off `migrate` service (`artisan migrate --force`);
`web`, `worker` and `scheduler` start only after it has finished
successfully. A satellite never runs migrations: it has no database.

The web interface is then on <http://localhost:8080>. Tests run with Pest
against PostgreSQL: `./vendor/bin/pest`, or `docker build -f docker/Dockerfile
--target test .` for the image's test stage.

## Documentation

Setup, operations and the full picture of how Syncthing, Prowlarr, Sonarr,
Radarr and your media server fit together will live on the project's
documentation site.

## License

MIT — see [LICENSE](LICENSE).
