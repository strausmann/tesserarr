---
title: Sign-in methods
description: Which sign-in methods exist, how they bind to accounts and how far an upstream second factor is trusted.
---

You switch each method on or off: passkey, user name and password, OpenID Connect (several providers in parallel), Plex
and the header sign-in of a forward-auth proxy (Authelia, oauth2-proxy and similar, decision record 0010). Every method
signs in an existing account; none creates one (decision record 0004).

**Second factor of an upstream provider.** Per method you choose (decision record 0015):

| Setting | For |
| --- | --- |
| Ask for Tesserarr's own second factor | every method |
| Trust the upstream sign-in | every method |
| Trust it when proven (`amr` in the ID token of this sign-in) | OpenID Connect |

"When proven" reads only the `amr` claim of the current ID token and checks `auth_time` against `max_age`; a missing
claim counts as not proven.

**Status:** sign-in methods arrive with 0.1.0.
