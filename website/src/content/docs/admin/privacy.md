---
title: Personal data
description: What Tesserarr encrypts, how addresses are found and confirmed, changing the keys, and where the protection ends.
---

Tesserarr keeps as little personal data as it can, and what it keeps is encrypted at rest.

## What is encrypted

| Data | Where | How |
| --- | --- | --- |
| Account address (confirmed, and the one waiting for its link) | `users.email`, `users.pending_email` | encrypted with `TESSERARR_PII_KEY` (AES-256-GCM) |
| Address a sign-in provider reported | `identities.email` | encrypted the same way |
| PGP key for encrypted mail | `users.pgp_public_key` | encrypted the same way |
| Mail on the queue (the confirmation link, later invitations) | `jobs`, `failed_jobs` | the queued job is encrypted with `APP_KEY`; jobs are short-lived |

An address is found through a blind index: an HMAC-SHA256 of the address with a key of its own,
`TESSERARR_BLIND_INDEX_KEY`. The address is normalised first (spaces around it removed, Unicode NFC, lower case, the
domain in its ASCII form), so `Alice@Example.org` and `alice@example.org` are one address. The index reveals nothing
without the key.

Both keys come from the environment only (see [Configuration](/tesserarr/install/configuration/)). They are never
stored in the database, written to a log or sent to the browser. The central instance does not start without them,
and refuses a `TESSERARR_PII_KEY` that equals `APP_KEY`.

## Addresses: optional, unique, confirmed

- An account needs no address; it signs in with its username.
- An address counts only after its owner opened the link sent to it. The link works once and for 60 minutes. Until
  then the address is reserved for 24 hours; after that anybody may claim it again.
- One address belongs to one account. Entering an address that another account holds or reserves is refused with
  "This address is already the address of another account", without naming that account. A user can check ten
  addresses an hour; each refusal is in the audit log.
- Changing a confirmed address keeps the old one valid until the new one is confirmed, and the old address is told
  about the change.
- Addresses can be entered only while the operator allows them (setting `email.allowed`) and mail can be sent. With a
  mailer that only writes to the log, nothing can be confirmed.
- The address a provider reports stays with that sign-in method. It is not unique, and no sign-in ever changes the
  account address. When an account has no address, *My account* offers the provider's address to take over.
- An operator enters an address for somebody on the user's page; it counts once its owner confirms it. A reservation
  can be released there at once.

## Who sees an address

- Everyone sees their own addresses and their own activity under *My account*.
- The account addresses of others are shown only to holders of `users.view`.
- The audit log stores ids, never names or addresses; names are looked up when the log is shown.
- Every log channel replaces addresses with `[email]`, also in the messages and traces of errors.

## Changing the keys

1. Put the old key into `TESSERARR_PII_PREVIOUS_KEYS` (comma-separated, several are possible) and the new one into
   `TESSERARR_PII_KEY`. For the blind index, put the old key into `TESSERARR_BLIND_INDEX_PREVIOUS_KEY` and the new one
   into `TESSERARR_BLIND_INDEX_KEY`.
2. Restart. Everything stays readable: values written with a previous key are still decrypted.
3. Count first, then rewrite:

   ```bash
   php artisan tesserarr:pii:rotate --dry-run
   php artisan tesserarr:pii:rotate
   ```

   The command rewrites every value with the current key and computes the blind index again when a previous blind
   index key is set. It works in batches and can be run again. It prints counts only.
4. Remove the previous keys and restart.

## Where the protection ends

Encryption protects against a stolen database dump, a backup and a shared log. It does not protect against somebody
who has taken over the running application: it holds the keys while it runs.
