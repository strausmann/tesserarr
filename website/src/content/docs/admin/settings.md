---
title: General settings
description: Name, language, time zone and defaults of the instance, set in the interface, and what stays in the environment.
---

Under *Administration, General* the operator sets what belongs to the instance as a whole. Each change is logged in the
[audit log](/tesserarr/admin/audit-log/) with the setting's key, never with its value. The page needs the permission
`settings.manage`.

## What you can set

| Setting | Key | Default | Meaning |
| --- | --- | --- | --- |
| Display name | `instance.display_name` | `Tesserarr` | Shown in the header and on the sign-in page. 1 to 60 characters, no control characters. |
| Default language | `locale.default` | `en` | `de` or `en`. Applies to a person whose profile and browser name no language. |
| Time zone | `time.zone` | `UTC` | An IANA name such as `Europe/Berlin`. The audit log shows its times in this zone. |
| List size when loading | `ui.page_size` | `50` | `25`, `50` or `100` entries per request, in the audit log and the catalogue. |
| Default theme | `ui.theme_default` | `system` | `system`, `light` or `dark`, until somebody chooses their own in *Account*. |
| Validity of new invitations | `invitations.default_days` | `7` | 1 to 90 days. Can be changed when inviting. |
| Audit log retention | `audit.retention_days` | `365` | 30 to 3650 days. |
| Backups to keep | `backups.keep` | `5` | 1 to 50. `tesserarr:migrate` keeps this many backups. |

A value that is not valid is refused with the field named, and nothing of the form is saved. A save that changes
nothing leaves no entry in the log.

## Emergency override by environment variable

Each setting has an environment variable that overrides it. It is meant for an emergency, for example a wrong time
zone that locks you out of reading dates. A variable that is set wins over the stored value, and the page then shows
the field locked: "Overridden by the environment. Set through `TZ`." The page refuses to change a locked field. Remove
the variable and restart to edit the setting in the interface again.

| Setting | Variable |
| --- | --- |
| `instance.display_name` | `TESSERARR_DISPLAY_NAME` |
| `locale.default` | `TESSERARR_DEFAULT_LOCALE` |
| `time.zone` | `TZ` |
| `ui.page_size` | `TESSERARR_PAGE_SIZE` |
| `ui.theme_default` | `TESSERARR_THEME_DEFAULT` |
| `invitations.default_days` | `TESSERARR_INVITATION_DAYS` |
| `audit.retention_days` | `TESSERARR_AUDIT_RETENTION_DAYS` |
| `backups.keep` | `TESSERARR_BACKUP_KEEP` |

An empty variable counts as not set. A variable with a value the setting does not accept is ignored, and the page says
so; only `TESSERARR_BACKUP_KEEP` is stricter: `tesserarr:migrate` stops when it is not a whole number from 1 to 50.

## What stays in the environment

The platform URL (`APP_URL`, it decides the passkey domain), the trusted proxy addresses
(`TESSERARR_TRUSTED_PROXIES`), the database access, the keys and the role are not settings. The card *Set through
the environment* shows them read-only: URL, proxies and role by value, keys and the database password only as *set* or
*not set*. The card also shows how Tesserarr sees your own request (client address, proxy address), which helps to
check the proxy list. See [configuration](/tesserarr/install/configuration/).

## On the command line

```bash
php artisan tesserarr:settings:get ui.page_size   # value in force and where it comes from
php artisan tesserarr:settings:get                # all settings
php artisan tesserarr:settings:set ui.page_size 100
```

The console uses the same rules as the page and is logged with the console as origin. A setting that an environment
variable overrides is refused here too: remove the variable first.
