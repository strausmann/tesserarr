<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Decision records

The decisions that shape Tesserarr, with their context and consequences, so that later contributors can see **why**
something is built the way it is.

ADR-0001 to ADR-0011 were first recorded for the Go version of Tesserarr (tag `archive/go-0.0.2`, in German). With the
move to Laravel they were carried over in English; each says in its section "History" what changed. The German
originals stay in the tag as the historic record.

## Rules

- **What:** every significant decision about architecture, technology or operation, with context and consequences. Not
  for trivia or obvious details of an implementation.
- **Numbering:** `NNNN-kebab-slug.md`, four digits, in sequence. A number is **never reused**, also not for a superseded
  record.
- **Status:** `proposed`, then `accepted`, then `superseded` or `deprecated`.
- **Lineage, on both records:** `Supersedes` and `Superseded by` for a complete replacement (the old record becomes
  `superseded`); `Revises` and `Revised by` for a partial change (the old one stays `accepted`); `Related` for a
  reference without replacement. When a record is superseded, only its header changes; its context and decision stay as
  they were.
- **Registry:** every new record, and every change of status, is entered in the table below in the same pull request.
- **Language:** English. Code, commands and identifiers as in the code.
- **No real data:** no names, addresses, host names or other details of a real installation, not even in examples.

New record: copy an existing one as a template, take the next free number and add it to the table.

## Registry

| No. | Title | Status | Date |
| --- | --- | --- | --- |
| [0001](0001-provider-side-service-receiver-instance-optional.md) | Provider-side service; a receiver instance is optional | accepted | 2026-08-13, revised 2026-11-24 |
| [0002](0002-usenet-emulation-instead-of-torrent.md) | Usenet emulation instead of torrent | accepted | 2026-08-13 |
| [0003](0003-hardlink-first-copy-only-with-space.md) | Hardlink first, a copy only when there is space | accepted | 2026-08-13, revised 2026-11-24 |
| [0004](0004-invitation-required-sign-in-delivers-identity.md) | Invitation required; a sign-in method delivers only the identity | accepted | 2026-08-13, revised 2026-11-24 |
| [0005](0005-go-core-separate-interface-one-image.md) | Go core, separate interface, one image | superseded by 0012 | 2026-08-13 |
| [0006](0006-ui-gate-as-a-hard-gate.md) | The interface check as a hard gate | accepted | 2026-08-13 |
| [0007](0007-session-for-the-interface-tokens-for-other-systems.md) | Session for the interface, tokens for other systems, separate paths | accepted | 2026-08-13, revised 2026-11-24 |
| [0008](0008-revocation-acts-on-both-paths.md) | Revocation acts on both paths; the Syncthing link belongs to the user | accepted | 2026-08-13, reviewed 2026-11-24 |
| [0009](0009-two-separate-log-streams.md) | Two separate log streams | accepted | 2026-08-13, reviewed 2026-11-24 |
| [0010](0010-forward-auth-as-a-second-entry-path.md) | Forward-auth as a second entry path | accepted | 2026-08-14, revised 2026-11-24 |
| [0011](0011-design-system-werkstatt.md) | Design system "Werkstatt" | superseded by 0012 | 2026-08-14 |
| [0012](0012-laravel-app-one-image-central-and-satellite-roles.md) | Laravel app, one image, central and satellite roles | accepted | 2026-11-24 |
| [0013](0013-satellite-pull-model.md) | Satellite pull model | accepted | 2026-11-24 |
| [0014](0014-reachability-through-tunnels-no-own-relay.md) | Reachability through tunnels, no own relay | accepted | 2026-11-24 |
| [0015](0015-login-methods-and-upstream-mfa-trust.md) | Sign-in methods and trust in an upstream second factor | accepted | 2026-11-24 |
| [0016](0016-single-authorization-function.md) | Single authorization function | accepted | 2026-11-24 |
