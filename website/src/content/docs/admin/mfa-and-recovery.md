---
title: MFA and recovery
description: Require a second factor, and get someone back in who lost theirs.
---

- You can make a second factor mandatory for everyone. **Operator rights always need it.**
- A passkey with user verification is enough on its own; a password account adds a passkey or TOTP.
- Ten recovery codes per second factor, stored as hashes, shown once.
- The last resort: you remove the lost passkey or factor and issue a [recovery invitation](/tesserarr/admin/invitations/).
- Every recovery is written to the [audit log](/tesserarr/admin/audit-log/).

**Status:** MFA and recovery arrive with 0.1.0.
