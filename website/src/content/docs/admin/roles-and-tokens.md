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

## The catalogue

| Permission | What it allows |
| --- | --- |
| `users.view` | See the user list |
| `users.manage` | Change the role of an account, lock and unlock accounts |
| `roles.manage` | Create own roles and change or delete them |
| `invitations.create`, `invitations.revoke` | Create and revoke invitations |
| `tokens.manage_own`, `tokens.manage_all` | Manage the own API tokens, or every account's |
| `login_methods.manage` | Configure the sign-in methods |
| `shares.manage`, `grants.manage` | Manage media shares, give grants |
| `catalog.browse`, `catalog.view_all` | Browse what is shared with you, see the whole catalogue |
| `connections.view`, `connections.manage` | See and manage the connections to the arr apps, Plex and Syncthing |
| `satellites.manage` | Register and revoke satellites |
| `status.view` | See the status page |
| `audit.view` | Read the audit log |
| `settings.manage` | Change the settings |
| `satellite.execute` | Run jobs; for satellite tokens only, never for accounts |

*Operator* holds every permission except `satellite.execute`; *User* holds `catalog.browse` and `tokens.manage_own`.
Neither built-in role can be changed or deleted, not even with `roles.manage`. Every permission except
`catalog.browse` and `tokens.manage_own` counts as administrative: an account with one of them sees the operator's
navigation.

## Own roles

Under *Roles and permissions* you create a role, tick its permissions and save. You can tick only the permissions you
hold yourself, and you can change or delete a role only when you hold all of its permissions. Deleting a role gives its
accounts the role *User*. Each change is logged with the permissions added and removed.

## How a permission is checked

One function decides every permission, in pages, the API and the command line (decision record 0016). It allows when
the account holds the permission **now** and is not locked, and, for a request with an API token, when the token carries
it as well. A demotion or a lock applies to the next request; nobody needs to sign in again.

The [API reference](/tesserarr/reference/api/) lists the permission each operation needs.
