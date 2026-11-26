<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0016: Single authorization function

- **Status:** accepted
- **Date:** 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** [ADR-0007](0007-session-for-the-interface-tokens-for-other-systems.md)
- **Revised by:** none
- **Related:** [ADR-0015](0015-login-methods-and-upstream-mfa-trust.md)

## Context

Permissions are checked in many places: pages, the session API, the token API, satellites, the command line. Each place
with its own check (a role test here, a token ability there, a shortcut of the framework elsewhere) is a place where the
rules can drift apart, and the most common way an authorization bug arises: one caller forgets half the rule. On the
token path two conditions must hold together: the owner holds the permission **now**, and the token carries it.

## Decision

1. Roles and API tokens draw on **one** permission catalogue. Preset roles: operator (everything) and user; own roles in
   between; several operators allowed. Roles live in Tesserarr, not at the sign-in provider.
2. **Every permission decision goes through one function**, `App\Authorization\Authorizer::allows($owner, $credential,
   $permission)`. It allows only when the owner holds the permission now (a user through their roles and not locked, a
   satellite exactly `satellite.execute` and not revoked) **and**, for a token, the token carries it. The result names
   its reason (`ok`, `owner_lacks_permission`, `token_lacks_permission`, `locked`, `revoked`, `token_missing`).
3. Controllers, middleware and policies call it through Laravel's gate (`can:` middleware, `authorize()`). A direct role
   or permission check of its own, or a shortcut around the gate, is not allowed in `app/`; static analysis refuses it
   outside the few classes that implement the rules.
4. **Guard rules:** the last operator cannot remove or demote themselves; nobody grants a permission they lack; every
   change of permissions is logged.
5. In the OpenAPI document each operation names the permission it needs, read from the same `can:` middleware that
   checks it.

## Consequences

- One place to review, test and audit; a test can count every protected route against it.
- A demoted owner takes their tokens down at once, without touching the tokens.
- The gate integration of the framework is used, but its own permission shortcut is switched off, so `$user->can()`
  cannot bypass the function.
- A permission check that needs more than owner and credential (for example a grant on a folder) builds on this
  function instead of replacing it.
