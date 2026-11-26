<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0002: Usenet emulation instead of torrent

- **Status:** accepted
- **Date:** 2026-08-13
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0001](0001-provider-side-service-receiver-instance-optional.md), [ADR-0004](0004-invitation-required-sign-in-delivers-identity.md)

## Context

Sonarr and Radarr know two ways to obtain files, torrent and Usenet. Each consists of a search part (indexer) and a
fetch part (download client) behind documented interfaces. To be accepted as a source, Tesserarr has to look like one
of the two; the arr apps know no third.

Emulating only the indexer fails: there is no real download, the file already lies at the provider and only has to
travel over Syncthing, so a real download client would have nothing to fetch.

Torrent brings terms without a counterpart here: a magnet link identifies content by a hash of a swarm, ratio and
seeding assume a swarm that does not exist. Usenet describes what Tesserarr does: a job is accepted, makes progress, is
done and then lies in a path.

## Decision

1. Tesserarr emulates **both** parts: a Newznab-compatible indexer for search and a SABnzbd-compatible download client
   for the job state (0.3.0).
2. It emulates **Usenet**, not torrent.
3. Search returns **only** hits from what is granted to the asking user (the grant check of the catalogue and the
   identity of [ADR-0004](0004-invitation-required-sign-in-delivers-identity.md)). Content that is not granted cannot
   be found.
4. A release name is built from metadata, file and quality properties so that Sonarr and Radarr recognise it by their
   own naming rules; without it they drop every hit.

## Consequences

- The arr apps need no special treatment: Prowlarr, Sonarr and Radarr connect through their existing Newznab and SABnzbd
  settings, without a fork or plug-in.
- The receiver needs no torrent client and no port forwarding.
- The interfaces still have to be verified against real instances; they and the release name are the places with the
  highest risk of rework.
- The emulation synthesises answers that look like Usenet results without any Usenet article behind them. Edge cases
  the real ecosystem solved (multi-part downloads, samples, passwords, failed segments) have no natural counterpart.
- Logs and the download client view show Usenet terms for a service that is not Usenet; the documentation explains
  that.

**Revisit** when the emulation proves so fragile in practice that a qBittorrent-compatible interface would be easier
despite the poor fit of its terms.

## History

Recorded for the Go version (tag `archive/go-0.0.2`) and carried over unchanged.
