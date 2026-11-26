<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0008: Revocation acts on both paths; the Syncthing link belongs to the user

- **Status:** accepted
- **Date:** 2026-08-13, reviewed 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0001](0001-provider-side-service-receiver-instance-optional.md), [ADR-0013](0013-satellite-pull-model.md)

## Context

A revoked friend loses access in two places: on the request path (sign-in, tokens, new requests) and on the data path
(the Syncthing folders shared with their device). A revocation that reports "done" while the data path is still open is
the failure this record prevents.

## Decision

1. There are **two modes**, decided by the credentials that are configured, not by a switch that could contradict
   reality.
2. **With a write-capable Syncthing connection** (managed mode) Tesserarr creates, shares and on revocation unshares the
   folders itself.
3. **Without it** the guided way applies: the operator sets the folder sharing up by hand and removes it by hand; the
   revocation shows "incomplete" until the operator confirms the removal.
4. **The failure case closes.** When the automatic removal fails (Syncthing unreachable, rights missing, folder ID
   changed), the revocation is **not** done: the same "incomplete" state as in the guided way, with its own log entry.
5. **In both modes the request path closes at once**: the revoked user can no longer sign in, use a token or make a
   request, however the Syncthing side is taken down.
6. **The Syncthing link belongs to the user, not to an identity.** It describes a person's device, not the way they sign
   in; tied to an identity, a second sign-in method would create a second link for the same device.
7. **A satellite holds no link.** It knows neither users nor devices; the link and its state live in the central
   instance, and the revocation is decided and recorded there. A satellite only carries out the file jobs it is given.

## Consequences

- A revocation never claims more than it did; "incomplete" is a visible state, not a silent gap.
- Revoking a satellite or losing one does not touch any user's link.
- Several devices per friend are not planned; if they come, they are a relation between user and devices of their own.

## History

Recorded for the Go version (tag `archive/go-0.0.2`). Reviewed for the satellite of the Laravel version: point 7 added.
