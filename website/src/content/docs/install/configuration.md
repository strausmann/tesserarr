---
title: Configuration
description: Every environment variable of Tesserarr, its default and what it does.
---

Tesserarr reads its configuration from the environment. Settings that belong in the web interface (sign-in methods,
the MFA rule, mail, and the [general settings](/tesserarr/admin/settings/) such as name, language and time zone) are set
there, not here. Each release adds the variables of its features to this page.

## Required

| Variable | Meaning |
| --- | --- |
| `APP_KEY` | Key for sessions and encrypted values. Generate it once with `artisan key:generate --show` and keep it: without it, encrypted values cannot be read again. Secret. |
| `APP_URL` | The address the web interface is opened at, for example `https://media.example.org`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | The PostgreSQL 18 database of the central instance. `DB_PASSWORD` is secret. |
| `TESSERARR_PII_KEY` | Key for personal data (the e-mail addresses and PGP keys): 32 bytes, raw or as `base64:` followed by 32 bytes in Base64, for example from `echo "base64:$(openssl rand -base64 32)"`. Must differ from `APP_KEY`; the central instance does not start without it. Secret. See [Personal data](/tesserarr/admin/privacy/). |
| `TESSERARR_BLIND_INDEX_KEY` | Key of the blind index by which an address is found: at least 32 bytes, generated like `TESSERARR_PII_KEY` but a different value. Changing it needs `tesserarr:pii:rotate`. Secret. |

## Changing the keys for personal data

| Variable | Default | Meaning |
| --- | --- | --- |
| `TESSERARR_PII_PREVIOUS_KEYS` | empty | Old values of `TESSERARR_PII_KEY`, comma-separated, still used to read until `tesserarr:pii:rotate` rewrote everything. Secret. |
| `TESSERARR_BLIND_INDEX_PREVIOUS_KEY` | empty | The old `TESSERARR_BLIND_INDEX_KEY`; while it is set, `tesserarr:pii:rotate` computes the blind index again. Secret. |

## Mail

| Variable | Default | Meaning |
| --- | --- | --- |
| `MAIL_MAILER` | none (`log` in the local environment only) | The mailer for confirmation links. Without a mailer no account address can be entered. The `log` mailer, which writes messages with their addresses into the log, exists only with `APP_ENV=local`. |

## Roles and network

| Variable | Default | Meaning |
| --- | --- | --- |
| `TESSERARR_ROLE` | `central` | `central` or `satellite`. Any other value stops the start. |
| `TESSERARR_TRUSTED_PROXIES` | empty | Reverse proxies whose `X-Forwarded-For` header is trusted, comma-separated addresses or ranges, for example `192.0.2.10`. Empty trusts none. |
| `SESSION_ENCRYPT` | `true` | Sessions are stored encrypted with `APP_KEY`. Switching it on for an existing installation signs everybody out once (the old sessions cannot be read). |
| `TESSERARR_TRUSTED_HOSTS` | empty | Host names the instance answers to besides the host of `APP_URL`, comma-separated. Requests with any other `Host` header are refused; `localhost` and `127.0.0.1` stay allowed for the health check inside the container. A health check that calls the container by its name, for example the target health check of Pangolin (`healthcheck.hostname`), sends that name as `Host`: put it here (for a container `tesserarr`: `TESSERARR_TRUSTED_HOSTS=tesserarr`), or the check gets 400. Links in mails are always built from `APP_URL`. |

## Backups and logs

| Variable | Default | Meaning |
| --- | --- | --- |
| `TESSERARR_BACKUP_DIR` | `/var/lib/tesserarr/backups` | Where `tesserarr:migrate` writes the `pg_dump` backup before it migrates. Mount a volume here. |
| `TESSERARR_BACKUP_KEEP` | `5` | How many backups to keep, 1 to 50. The setting *Backups to keep* of the interface; the variable overrides it, for emergencies only. |
| `TESSERARR_PG_DUMP` | `pg_dump` | The `pg_dump` program used for the backup. |
| `TESSERARR_ACCESS_LOG` | `php://stdout` | Where the access log goes (combined format). |

## Emergency overrides of the general settings

These variables override the [general settings](/tesserarr/admin/settings/) of the interface. Leave them unset in normal
operation: a variable that is set locks its field in the interface.

| Variable | Overrides |
| --- | --- |
| `TESSERARR_DISPLAY_NAME` | Display name |
| `TESSERARR_DEFAULT_LOCALE` | Default language, `de` or `en` |
| `TZ` | Time zone, an IANA name |
| `TESSERARR_PAGE_SIZE` | List size, `25`, `50` or `100` |
| `TESSERARR_THEME_DEFAULT` | Default theme, `system`, `light` or `dark` |
| `TESSERARR_INVITATION_DAYS` | Validity of new invitations, 1 to 90 |
| `TESSERARR_AUDIT_RETENTION_DAYS` | Audit log retention, 30 to 3650 |

## Updates

| Variable | Default | Meaning |
| --- | --- | --- |
| `TESSERARR_RELEASE_CHECK` | `false` | Opt-in. `true`: the central instance asks the GitHub releases of Tesserarr every six hours whether a newer release exists, with only the user agent `Tesserarr`, and shows it to the operator under "About". `false` sends nothing. See [version and updates](/tesserarr/using/updates/). |
| `TESSERARR_RELEASE_CHECK_TIMEOUT` | `5` | Seconds the check waits for an answer. |
| `TESSERARR_RELEASE_CHECK_URL` | the latest release of `strausmann/tesserarr` on `api.github.com` | Where the check asks, for a mirror that answers like the GitHub releases API. |
