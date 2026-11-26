---
title: Invitations
description: Invite friends, revoke an invitation and restore access to an existing account.
---

Every account starts with an invitation (decision record 0004). You create one in the web interface, with `artisan` or
through the API, and hand the link on however you like, by mail if you set mail up.

- An invitation works **once**, for a limited time (seven days by default), and can be revoked until it is used.
- Only its hash is stored, so a leaked database reveals no usable link.
- Two people redeeming the same link at once: exactly one succeeds.
- A **recovery invitation** binds a new sign-in method to an **existing** account. Grants, quotas, the audit trail and
  the Syncthing link stay. Use it when someone lost every passkey and factor.

**Status:** invitations arrive with 0.1.0.
