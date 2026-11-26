---
title: Roles and tokens
description: One catalogue of permissions for roles and API tokens, and the rules that protect it.
---

Roles and API tokens draw on **one** catalogue of permissions (decision records 0007 and 0016).

- **Roles.** *Operator* has every permission, *User* the ones a friend needs; your own roles sit in between. There can be
  several operators.
- **Guard rules.** The last operator cannot remove or demote themselves; nobody grants a permission they lack; every
  change of permissions is logged.
- **API tokens** carry the permissions picked for them, with presets for "arr connection", "satellite" and "create
  invitations". A token never does more than its owner can do *now*: a demoted owner takes their tokens down with them.
- Tokens are stored as hashes, show when they were last used, can expire and can be revoked at any time.

The [API reference](/tesserarr/reference/api/) lists the permission each operation needs.

**Status:** roles and tokens arrive with 0.1.0.
