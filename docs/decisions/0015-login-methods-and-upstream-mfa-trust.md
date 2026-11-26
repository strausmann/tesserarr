<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0015: Sign-in methods and trust in an upstream second factor

- **Status:** accepted
- **Date:** 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** [ADR-0004](0004-invitation-required-sign-in-delivers-identity.md)
- **Revised by:** none
- **Related:** [ADR-0010](0010-forward-auth-as-a-second-entry-path.md), [ADR-0016](0016-single-authorization-function.md)

## Context

Friends sign in through different methods: some want a passkey and no e-mail address, some a password, some already have an
OpenID Connect provider or a Plex account, some sit behind a forward-auth proxy. Several of these methods have a second
factor of their own upstream. Asking for Tesserarr's own factor on top annoys; trusting an upstream sign-in blindly
weakens the MFA rule, especially for operator rights.

## Decision

1. **Methods, each switched on by the operator, several per user, all on the same account:** passkey (no e-mail needed),
   user name and password (no e-mail needed; the user name is the sign-in name), OpenID Connect (several providers in
   parallel, an identity is the pair of provider and `sub`), Plex, and the header sign-in of a forward-auth proxy
   ([ADR-0010](0010-forward-auth-as-a-second-entry-path.md)). An e-mail address is optional where the operator allows it.
2. **MFA.** The operator can make a second factor mandatory for everyone. **Operator rights always need it.** A passkey
   with user verification counts as MFA on its own; a password account adds a passkey or TOTP. Ten recovery codes per
   factor, shown once, stored as hashes, each valid once, renewable at any time (the old set expires). Security
   changes need a fresh passkey or TOTP proof, not the password alone.
3. **Trust in an upstream factor, set per method:**

   | Setting | Offered for |
   | --- | --- |
   | Ask for Tesserarr's own second factor | every method |
   | Trust the upstream sign-in | every method |
   | Trust it when proven | OpenID Connect |

4. **"When proven"** reads only the `amr` claim of the ID token of the current sign-in. MFA counts when it contains `mfa`,
   or a knowledge factor (`pwd`, `kba`, `pin`) together with a possession factor (`otp`, `hwk`, `swk`, `sms`, `tel`,
   `fido`, `rsa`, `totp`, `hotp`). A missing `amr` counts as not proven. Tesserarr sends `max_age` and checks
   `auth_time`. Plex and the header sign-in do not get this setting; for Plex, trial V3 checks whether any usable signal
   exists.
5. Every method only delivers an identity; admission stays with the invitation
   ([ADR-0004](0004-invitation-required-sign-in-delivers-identity.md)).

## Consequences

- People sign in the way they already do, without a second account at a mail provider.
- The MFA rule stays meaningful: an upstream factor counts only where the operator decided so, and "when proven" relies
  on a claim of this very sign-in, not on a setting of the provider.
- Providers differ in what they put into `amr`; Authentik, Authelia, Keycloak and Entra ID set it, Zitadel with
  reservations. A provider that omits it falls back to Tesserarr's own factor.
