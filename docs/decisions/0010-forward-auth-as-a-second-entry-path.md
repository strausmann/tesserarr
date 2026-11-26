<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0010: Forward-auth as a second entry path

- **Status:** accepted
- **Date:** 2026-08-14, revised 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0004](0004-invitation-required-sign-in-delivers-identity.md), [ADR-0015](0015-login-methods-and-upstream-mfa-trust.md)

## Context

Many homelabs put an authenticating proxy in front of their services (Authelia, oauth2-proxy, Pangolin and similar).
It signs people in and passes the identity on in request headers. Accepting such headers from anyone would let every
client claim any identity.

## Decision

1. A separate entry path for the header sign-in checks **trust first**: the request comes from an address in
   `TESSERARR_TRUSTED_PROXIES` **and** carries a shared secret with a minimum length in its own header. Without both it is
   refused, before the identity headers are even looked at.
2. A known identity gets a session at once, with the same session rotation as every other sign-in.
3. An unknown identity becomes an account **only** with a valid, unredeemed invitation, in the same transaction as the
   account is created, as [ADR-0004](0004-invitation-required-sign-in-delivers-identity.md) requires for every sign-in
   method. Two simultaneous first sign-ins of the same identity end in one account and two sessions, never in an error.
4. The header names are configurable, with presets per proxy. A proxy without a stable user key (for example Authelia or
   oauth2-proxy without a configured key header) makes the start fail until the key header is set explicitly.
5. **Roles live in Tesserarr, not in the proxy.** Headers deliver the identity only; no group or role header maps to a
   permission. Roles are assigned in Tesserarr like for every other sign-in method.
6. The header sign-in is one method among the others, switched on by the operator; OpenID Connect, passkeys and
   passwords stay available. Whether its sign-in counts as a second factor is set per method
   ([ADR-0015](0015-login-methods-and-upstream-mfa-trust.md)); "trust it when proven" is not offered for headers.

## Consequences

- Homelabs with an existing proxy sign in once, without a second password.
- The secret and the trusted addresses are two independent conditions; one alone is not enough.
- Misconfiguration (a proxy that does not strip incoming identity headers) is still possible outside Tesserarr; the docs
  say how to check it.

## History

Recorded for the Go version (tag `archive/go-0.0.2`), where the proxy's headers could also carry roles. Revised with
the move to Laravel: roles stay in Tesserarr (point 5), the secret has a minimum length (an audit finding of the Go
version), and a lost race on the first sign-in ends in a sign-in instead of an error.
