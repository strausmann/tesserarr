<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0001: Provider-side service; a receiver instance is optional

- **Status:** accepted
- **Date:** 2026-08-13, revised 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0002](0002-usenet-emulation-instead-of-torrent.md), [ADR-0003](0003-hardlink-first-copy-only-with-space.md), [ADR-0008](0008-revocation-acts-on-both-paths.md)

## Context

Tesserarr lets befriended homelab operators give each other access to parts of their media libraries. The people
involved already have a working Syncthing link, so the transport exists before Tesserarr does. Open was who runs the
new service:

- **A, both sides with their own protocol.** Complete, but a second protocol to design, version and keep compatible.
- **B, provider plus a small receiving agent** that reports the transfer state of the receiver's Syncthing.
- **C, provider only.** The receiver installs nothing and uses the Syncthing and, if they like, the arr apps they
  already run.

What an agent or a protocol would answer (how far is the transfer, did the file arrive, was it deleted) is answered by
Syncthing's own REST API from the provider's side already (`GET /rest/db/completion`). Every extra installation on the
receiving side lowers the chance that anyone but the author uses the service.

## Decision

1. Tesserarr runs **on the provider's side**. A receiver needs nothing but Syncthing and, if wanted, their arr apps or a
   browser.
2. Progress is read from the **provider's own Syncthing instance**, not reported by the other side.
3. Receivers use the web interface or their own arr apps, which treat Tesserarr as an ordinary source through the
   emulation of [ADR-0002](0002-usenet-emulation-instead-of-torrent.md).
4. The file transport stays with Syncthing. Tesserarr decides what lies in which folder and when.
5. **A receiver may run Tesserarr as well (from 0.4.0), but never has to.** With an instance on both sides, the two
   recognise each other through the invitation, set up the Syncthing link and the folders on both sides, take them down
   on revocation and can report a real import. The receiving instance sets up its own intake (indexer and download
   client in its Sonarr, Radarr and Prowlarr) and keeps the API keys of its arr apps to itself. The instance-to-instance
   interface is versioned and mutually authenticated. Without it, everything works as in points 1 to 4.

## Consequences

- No installation is required on the receiving side; the optional instance only adds comfort.
- Progress is measured, not estimated or taken on trust.
- Without a receiver instance Tesserarr knows only what its own Syncthing reports about the receiver's device: progress,
  pause, missing share, deletion. There is no channel for anything beyond that.
- Telling "collected" from "expired" needs the folder to report the receiver's deletion back; how the managed folders
  are set up for that is part of 0.2.0.
- Tesserarr is bound to Syncthing as its only transport. Another transport would be a redesign, not a setting.
- The optional instance coupling is a second interface after all; it is versioned and authenticated on its own so that
  the provider-only way never depends on it.

## History

Recorded for the Go version (tag `archive/go-0.0.2`) as "the receiver installs nothing". Revised with the move to
Laravel: a receiver instance became optional (point 5) and is planned for 0.4.0.

## References

- Syncthing REST API, completion: <https://docs.syncthing.net/rest/db-completion-get.html>
