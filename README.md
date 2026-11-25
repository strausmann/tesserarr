<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Tesserarr

[![License: AGPL-3.0-or-later](https://img.shields.io/badge/License-AGPL--3.0--or--later-blue.svg)](LICENSE)
[![Status](https://img.shields.io/badge/status-early%20development-orange.svg)][ms-1]
[![PHP 8.5](https://img.shields.io/badge/PHP-8.5-777BB4.svg?logo=php&logoColor=white)](https://www.php.net)
[![Laravel 13](https://img.shields.io/badge/Laravel-13-FF2D20.svg?logo=laravel&logoColor=white)](https://laravel.com)

Share parts of your media library with friends over an existing Syncthing link,
without torrents, without a public index and without doing it by hand.

Your friends browse the catalogue you chose to share and request a title. They
do so either through their own Sonarr or Radarr, which picks it up as if it came
from any other source, or directly in the web interface (both from 0.3.0). The
files travel over Syncthing. You decide who sees which share, how much they may request and how long
a file stays around if nobody collects it.

Tesserarr is a Laravel 13 application (PHP 8.5) with a Svelte 5 interface served
through Inertia and a PostgreSQL 18 database. It ships as one FrankenPHP-based
image.

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

One central instance holds the database, the interface and the rules. Optional
satellites run on other machines, for example next to the media storage. They have
no database: they pull jobs from the centre and create the hardlinks on the host
that holds the media. When several people receive the same title, Tesserarr
hardlinks it instead of copying it, so it occupies the disk once. An optional
transfer instance, a Syncthing on a cloud VM, receives each title once and serves
it from there, so you need not open your own Syncthing to your friends.

Sonarr and Radarr report an import by removing the download from the download
client's history ("Remove Completed"). Tesserarr treats that as the signal that
the transfer was collected.

## Features

Each item says in which release it arrives.

**Accounts and sign-in**

- Invitation-only accounts with atomic redemption (0.1.0)
- Password, passkeys and TOTP, with mandatory MFA for administrative rights (0.1.0)
- OIDC with several providers in parallel, Plex sign-in and forward-auth header
  sign-in (0.1.0)
- Confirmation of security-relevant changes with a fresh second factor (0.1.0)
- Optional email over SMTP or JMAP, with PGP per recipient; addresses are stored
  encrypted (0.1.0)

**Shares and catalogue**

- Media shares with fail-closed path resolution and grant rules per user (0.1.0)
- Browse, search and bulk actions over an incremental folder index (0.1.0)
- Friend view with the list of shares and key figures (0.1.0)
- User groups and quotas per media type (0.2.0 and 0.3.0)

**Syncthing delivery**

- Hardlink provisioning through satellites, with a copy fallback (0.2.0)
- Managed Syncthing folders and devices per user, transfer history and views
  (0.2.0)
- Optional transfer instance and optional own Syncthing relay (0.2.0)
- Pickup detection, grace period and clean-up (0.2.0)

**Arr integration**

- Read access to Sonarr, Radarr, Prowlarr, Syncthing and Plex connections (0.1.0)
- Emulated Newznab indexer and SABnzbd download client for your friends' own arr
  apps (0.3.0)
- Requests straight from the catalogue for people without arr apps (0.3.0)

**Operation**

- Setup checklist with a guided wizard, settings in the interface and a
  self-test (0.1.0)
- Append-only audit log and live status updates (0.1.0)
- Migrations that write a database backup first (0.1.0)
- Notification centre with mail, Teams, Discord and Telegram (0.2.0)

## Roadmap

**[0.1.0][ms-1]: foundation**

- Accounts, sign-in methods, roles and the audit log
- Invitations, setup wizard and settings in the interface
- Media shares, grant rules and the catalogue
- Satellite skeleton with pull-based read-only jobs
- Connections to Sonarr, Radarr, Prowlarr, Syncthing and Plex

**[0.2.0][ms-2]: first media path**

- Hardlink provisioning through satellites, with a copy fallback
- Managed Syncthing folders and devices, pickup detection and clean-up
- Transfer instance, own Syncthing relay and user groups
- Notification centre with mail, Teams, Discord and Telegram

**[0.3.0][ms-3]: arr integration**

- Newznab and SABnzbd emulation for your friends' own arr apps
- Requests straight from the catalogue
- Quotas per media type with a soft limit
- Warning system for conspicuous behaviour

**Later** ([0.4.0][ms-4], [0.5.0][ms-5], [1.0.0][ms-6])

- Instance pairing and import feedback
- Jellyfin and Emby support
- Operation with real participants, upgrade path and API stability commitment

[ms-1]: https://github.com/strausmann/tesserarr/milestone/1
[ms-2]: https://github.com/strausmann/tesserarr/milestone/2
[ms-3]: https://github.com/strausmann/tesserarr/milestone/3
[ms-4]: https://github.com/strausmann/tesserarr/milestone/4
[ms-5]: https://github.com/strausmann/tesserarr/milestone/5
[ms-6]: https://github.com/strausmann/tesserarr/milestone/6

## Status

Early development. Milestone [0.1.0][ms-1] is in progress and nothing is usable
yet.

## Requirements

Planned for the first release:

- Docker with Compose
- PostgreSQL 18 (included in the Compose file)
- A Syncthing instance that Tesserarr may control
- Optional: Sonarr, Radarr, Prowlarr and Plex

## Documentation

Setup, operations and the full picture of how Syncthing, Prowlarr, Sonarr,
Radarr and your media server fit together will live on the project's
documentation site.

## License

Tesserarr is licensed under the GNU Affero General Public License v3.0 or later
(AGPL-3.0-or-later), see [LICENSE](LICENSE). Contributions need a signed
[Contributor License Agreement](CLA.md), see [CONTRIBUTING.md](CONTRIBUTING.md).
The JMAP client in `packages/laravel-jmap-client/` (`strausmann/laravel-jmap-client`) is licensed under MIT.
