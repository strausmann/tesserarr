---
title: Sign in
description: Redeem an invitation and sign in with a passkey, a password or an external provider.
---

An invitation is a link that works once and for a limited time (seven days unless the operator chose otherwise). It
creates your account; there is no other way to get one.

The operator decides which sign-in methods exist. You can have several at once, all on the same account:

| Method | Needs an e-mail address |
| --- | --- |
| Passkey | no |
| User name and password | no |
| OpenID Connect (for example Authentik, Authelia, Keycloak, Entra ID) | the provider's |
| Plex account | the provider's |
| Sign-in by a proxy in front of Tesserarr (forward-auth) | no |

An external provider only signs in an account that exists. It never creates one without an invitation.

**Status:** sign-in arrives with 0.1.0.
