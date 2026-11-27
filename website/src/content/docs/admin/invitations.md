---
title: Invitations
description: Invite friends, revoke an invitation and restore access to an existing account.
---

Every account starts with an invitation (decision record 0004). You create one in the web interface, with `artisan` or
through the API, and hand the link on however you like. Sending it by mail from Tesserarr arrives with the mail setup.

- An invitation works **once**, for a limited time (seven days by default, the general setting "Validity of new invitations"),
  and can be revoked until it is used.
- Only its hash is stored, so a leaked database reveals no usable link. The link is shown once, when it is made.
- The token sits after the `#` of the link (`/invite#…`). Browsers never send that part, so it ends up in no access log,
  no referrer and no browser history entry of the server.
- Two people redeeming the same link at once: exactly one succeeds. The same person redeeming two links at once gets one
  account, and the second link stays unused.
- A **recovery invitation** binds a new sign-in method to an **existing** account. Role, grants, quotas, the audit trail
  and the Syncthing link stay. Use it when someone lost every passkey and factor.

## In the web interface

Under **Users** the section "Open invitations" lists every invitation nobody has redeemed yet, with its note, how long
it stays valid and who made it. **Invite** asks for a note (to recognise it later) and the validity (one, seven or 30
days), then shows the link once with a button to copy it. Open an invitation to see its details or revoke it.

A recovery invitation is made on the detail page of the account, under "Sign-in methods". It needs the permissions
"Create invitations" and "Manage users", and only someone who holds every permission of that account may make one: the
link is a way into it.

## With artisan

```sh
php artisan tesserarr:invite [--days=7] [--note="For Sam"]
php artisan tesserarr:invite:revoke 12
php artisan tesserarr:invite:recovery sam [--days=7]
```

`tesserarr:invite` is also how the first invitation is made on a fresh instance; there is no endpoint that makes one
without an account. Each command prints the id of the invitation and its link.

## Through the API

`POST /api/v1/invitations` with a token that carries `invitations.create` (the token preset "Create invitations") makes
an invitation and answers `201` with `id`, `url` and `expires_at`. Optional fields: `days` (1 to 90) and `note`.
`DELETE /api/v1/invitations/{id}` with `invitations.revoke` revokes one. The API reference lists both.

## In the audit log

Every invitation made, revoked or redeemed is in the audit log. A redemption names the account, the identity, the
sign-in method, the time and the source address. A link that cannot be used (unknown, used, revoked, expired) gets the
same answer for every reason; the reason is in the log only.

**Status:** invitations arrive with 0.1.0. The sign-in methods that redeem them (password and passkey, OIDC, Plex, the
header of a forward-auth proxy) are switched on one by one in the same release.
