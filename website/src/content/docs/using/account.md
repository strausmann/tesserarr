---
title: Your account
description: Passkeys, the second factor, recovery codes and your own API tokens.
---

- **Passkeys.** A passkey with user verification counts as two factors on its own.
- **Second factor.** With a password you add a passkey or an authenticator app (TOTP) when the operator requires it.
- **Recovery codes.** Ten codes per second factor, shown once, each valid once. Creating a new set voids the old one.
- **Security changes** (a new passkey, a new factor) need a fresh passkey or TOTP confirmation, not only the password.
- **API tokens** for your own scripts or arr apps: each token carries only the permissions you pick, never more than
  you have yourself, and can be revoked at any time.

**Status:** the account page arrives with 0.1.0.
