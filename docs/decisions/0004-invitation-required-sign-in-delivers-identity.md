<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0004: Invitation required; a sign-in method delivers only the identity

- **Status:** accepted
- **Date:** 2026-08-13, revised 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** [ADR-0015](0015-login-methods-and-upstream-mfa-trust.md)
- **Related:** [ADR-0002](0002-usenet-emulation-instead-of-torrent.md), [ADR-0010](0010-forward-auth-as-a-second-entry-path.md)

## Context

Tesserarr hands out access to a private library. The sign-in methods it supports know very different things about a
person: an OpenID Connect provider may know groups, Plex knows none, a forward-auth proxy knows what its operator
configured. Letting any of them decide who gets in would tie admission to the weakest of them and to group models that
can change without Tesserarr noticing.

## Decision

1. Access comes **only** from a redeemed invitation. There is no sign-up, whatever a sign-in method says about a person.
2. A sign-in method states **who** someone is, never whether they may enter.
3. An invitation is a random value of at least 128 bits, stored only as a hash, redeemable **once**, with an expiry
   (seven days by default) and revocable until it is used. Redemption is atomic: of two simultaneous attempts exactly one
   succeeds. It is handed on by mail or as a link; mail is optional.
4. Invitations are created in the web interface, with `artisan` **or through the API**. The very first one comes from
   `artisan`; there is no bootstrap endpoint.
5. A user can have several identities (several sign-in methods); grants, quotas and the audit trail belong to the user,
   not to an identity.
6. A **recovery invitation** binds a new sign-in method to an **existing** user. Grants, quotas, the audit trail and the
   Syncthing link stay. It is the last resort when someone lost every factor, and it is logged.
7. Automatic linking of an OpenID Connect identity by a confirmed e-mail address (an option per provider, off by
   default) binds **only to an existing account** and never creates one. It is therefore compatible with point 1: an
   account without an invitation cannot come about.
8. Every redemption is logged with the identity, the sign-in method, the time and the client address.

## Consequences

- The model works regardless of what a sign-in method knows about groups; it does not break at Plex.
- Admission is an explicit allow list in Tesserarr's own database, revocable at any time without the provider.
- Until it is redeemed, the link is the key. Whoever gets it first (a forwarded mail, a screenshot) can bind their own
  identity. Single use, expiry, revocation and the log reduce that risk but do not remove it. Without mail the link
  travels over informal channels, which makes it more likely to leak.
- Every new person needs an invitation by the operator. That fits a circle of friends and rules out an open user base.
- An error in the invitation code weighs heavily: no second check (such as a group test) would catch it.

**Revisit** when Tesserarr is meant for a much larger or open circle.

## History

Recorded for the Go version (tag `archive/go-0.0.2`). Revised with the move to Laravel: invitations through the API,
the recovery invitation and the rule for automatic linking (points 4, 6 and 7). The sign-in methods themselves and the
trust in an upstream second factor are [ADR-0015](0015-login-methods-and-upstream-mfa-trust.md).
