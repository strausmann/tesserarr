---
title: Users
description: Accounts, usernames, contact addresses, roles and locking an account.
---

Every person who uses Tesserarr has one account. New accounts come in through an
[invitation](/tesserarr/admin/invitations/) only.

## Username and addresses

- **Username.** The name you sign in with: 3 to 64 characters, lower case, letters, digits, dot, underscore and dash. A
  username never contains `@`, so it cannot be mistaken for an e-mail address. `Max` and `max` are the same name.
  Everyone can change their own username under *Account*; the change is logged.
- **Display name.** The name others see. Control characters are refused.
- **Account address.** An optional contact address of the account. It never identifies an account and is not a sign-in
  name.
- **Address at the provider.** When someone signs in through a provider (OIDC, Plex), Tesserarr keeps the address the
  provider reported with that sign-in method. The user list shows it separately from the account address.

When a provider sends an e-mail-shaped name (for example the user principal name of Entra ID), Tesserarr proposes a
username from the part before the `@` and numbers it when the name is taken: `bob`, `bob2`.

## Roles

An account has one role. Change it in the user list or on the command line:

```bash
php artisan tesserarr:role:assign bob operator
```

The command names the account it changed, for example `Bob, bob (id 7)`. The change applies to the next request of that
person; no new sign-in is needed. See [Roles and tokens](/tesserarr/admin/roles-and-tokens/) for what each role may do.

The first operator: once the first invitation is redeemed, give that account the role `operator` with the command above.

## Locking an account

A locked account is signed out on its next request, cannot sign in and its tokens stop working. Its data stays.

```bash
php artisan tesserarr:user:lock bob
php artisan tesserarr:user:unlock bob
```

The last active operator cannot be locked, demoted or removed. Lock or demote someone only with the permissions they
hold yourself: an account can never change an account that can do more than it can. Nobody can lock their own account
in the web interface.

Accounts are not deleted in the web interface; lock an account instead.

Every change on this page is written to the [audit log](/tesserarr/admin/audit-log/).
