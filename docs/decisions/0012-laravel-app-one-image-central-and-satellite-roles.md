<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0012: Laravel app, one image, central and satellite roles

- **Status:** accepted
- **Date:** 2026-11-24
- **Supersedes:** [ADR-0005](0005-go-core-separate-interface-one-image.md), [ADR-0011](0011-design-system-werkstatt.md)
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0006](0006-ui-gate-as-a-hard-gate.md), [ADR-0007](0007-session-for-the-interface-tokens-for-other-systems.md), [ADR-0013](0013-satellite-pull-model.md)

## Context

User management is the core of Tesserarr, not its edge: invitations, passkeys without e-mail, password accounts,
mandatory MFA, several operators, roles and fine-grained API tokens. Laravel has all of that maintained (Fortify,
passkeys, Sanctum); in Go every part had to be written and kept up by hand. The Go version (tag `archive/go-0.0.2`)
showed the cost. Files, on the other hand, often lie on another machine than the one that runs the web interface.

## Decision

1. Tesserarr is **one Laravel application** (Laravel 13, PHP 8.5) with **one image** and **one PostgreSQL database**.
2. The interface is Svelte 5 inside the application (the Laravel Svelte starter kit, Inertia), not a separate
   application. It follows the design line of Labelyard and BambuBridge, mobile first, in German and English.
3. The same image starts as **central instance** or **satellite** (`TESSERARR_ROLE`). Inside the central instance it
   runs as several **process roles**: web, worker (one or two), scheduler and the WebSocket server.
4. **A satellite has no database, no queue and no session.** The role sets cache, session and queue to in-memory values
   itself, independent of the environment, and any database connection fails.
5. There is **no second backend language**. A part moves out of PHP only when it measurably runs badly there, as a
   recorded decision.
6. The OpenAPI document of the token API is generated from the code (Scramble) and checked in CI, so the description
   cannot drift from the code.

## Consequences

- Maintained building blocks for everything around users and sign-in.
- One image, one version, one deploy, as before.
- The image is larger than the Go binary was and needs a PHP runtime (FrankenPHP).
- PHP needs its own tool chain for the gates (Pest, Larastan, Pint, Semgrep); its cost is measured per gate
  (`docs/measurements/v4-php-tooling.md`).
- The rule "a satellite has no database" is a hard rule of the code base, enforced by the role at boot and by tests.

## References

- Laravel starter kits: <https://laravel.com/docs/starter-kits>
- Scramble: <https://scramble.dedoc.co/>
