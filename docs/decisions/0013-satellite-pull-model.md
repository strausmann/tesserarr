<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0013: Satellite pull model

- **Status:** accepted
- **Date:** 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0003](0003-hardlink-first-copy-only-with-space.md), [ADR-0008](0008-revocation-acts-on-both-paths.md), [ADR-0009](0009-two-separate-log-streams.md), [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md), [ADR-0014](0014-reachability-through-tunnels-no-own-relay.md)

## Context

The file work (hardlinks, copies, reading the folder tree, talking to the local Syncthing) has to happen where the files
are, often a NAS, a cloud VM or a seedbox. The central instance with the users and the rules usually runs elsewhere. A
component next to the files that knows users or grants, or that listens for the centre, would put the user management at
risk on every machine that holds media.

## Decision

1. A satellite **knows no users and no grants**. It gets narrow jobs that the central instance has decided already:
   which file, which outgoing area.
2. It **dials out**. It fetches its jobs from the central instance over the token API and reports results; nothing on
   its host listens for the centre.
3. It has **no access to the database or the queue** of the central instance, only its own revocable token with exactly
   one permission, `satellite.execute`.
4. It checks **itself**, before every job, that the resolved path lies inside a media share assigned to it: a second,
   independent barrier after the central instance's grant check.
5. The exchange is robust by design: a heartbeat with its version, protocol and capabilities; backoff with jitter; the
   job ID as idempotency key (a repeated result changes nothing); an unsupported protocol answered with `426`; a job type
   the satellite lacks fails as `unsupported` at once.
6. A satellite never talks to the WebSocket server; the central instance broadcasts what a satellite reported.

## Consequences

- A compromised satellite costs that machine, not the user management.
- No port is opened on the machines that hold media.
- Jobs wait until the satellite fetches them; the delay is the poll interval.
- A first pairing with a one-time key is a precondition of 0.2.0; 0.1.0 registers satellites through `artisan`.
