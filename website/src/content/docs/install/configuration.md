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

## Roles and network

| Variable | Default | Meaning |
| --- | --- | --- |
| `TESSERARR_ROLE` | `central` | `central` or `satellite`. Any other value stops the start. |
| `TESSERARR_TRUSTED_PROXIES` | empty | Reverse proxies whose `X-Forwarded-For` header is trusted, comma-separated addresses or ranges, for example `192.0.2.10`. Empty trusts none. |

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
