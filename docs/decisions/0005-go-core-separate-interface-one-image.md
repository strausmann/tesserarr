<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0005: Go core, separate interface, one image

- **Status:** superseded
- **Date:** 2026-08-13
- **Supersedes:** none
- **Superseded by:** [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md)
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0007](0007-session-for-the-interface-tokens-for-other-systems.md)

This record is kept as it was decided for the Go version (tag `archive/go-0.0.2`). Tesserarr is now a Laravel
application; see [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md). The part "one image" carries
over.

## Context

Language and shape had to be chosen for the core (state machine, connections to the media server and Syncthing,
emulated indexer and download client, public API) and for the web interface.

- **Language of the core.** The arr apps are .NET, but Tesserarr talks to them over HTTP only, which is language
  neutral; .NET would only matter for a plug-in inside them, which was not planned. Go matched the existing tool chain
  of the author's projects and gives a small binary without a runtime.
- **Shape of the interface.** Two images (a Go core and a running Next process) would double the set-up and let the two
  drift apart. One image, with the interface built as a static Next export and embedded into the Go binary, means one
  version and one deploy.

## Decision

1. The core is written in **Go**.
2. The web interface is a separate **React/Next application, exported as static files**, built with Node 22 and pnpm.
3. There is **one** image, built in three stages: Node (static files), Go (`go:embed`, binary), `scratch` (delivery).
4. The public API `/api/v1` is the **only** data path of the interface; no server components with their own data
   access.

## Consequences

- One image, one version, one deploy; core and interface cannot drift apart.
- A very small image without a runtime.
- Everything around users (invitations, passkeys, MFA, roles, tokens) had to be built and kept up by hand in Go. That
  cost is the main reason this record was superseded.

## History

Superseded on 2026-11-24 by [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md).
