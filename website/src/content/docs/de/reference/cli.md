---
title: Befehlszeile
description: Jeder tesserarr-Befehl von artisan, mit seinen Argumenten und Optionen.
---

<!-- Diese Seite wird von `scripts/cli_reference.py` aus `php artisan list --format=json` erzeugt; ändere die Beschreibungen in `app/Console/Commands/`, nicht hier. -->

Führe einen Befehl über den Einstiegspunkt `artisan` des Images aus, zum Beispiel `docker compose run --rm --no-deps web artisan list tesserarr`. Die Beschreibungen stammen aus dem Code und sind englisch.

## `tesserarr:email:release-expired`

Free account addresses whose 24-hour reservation ran out without confirmation

### Aufruf

```text
tesserarr:email:release-expired
```

## `tesserarr:invite`

Invite somebody: prints a link that makes one new account

### Aufruf

```text
tesserarr:invite [--days [DAYS]] [--note [NOTE]]
```

### Optionen

| Option | Beschreibung | Standard |
| --- | --- | --- |
| `--days` | how many days the link stays valid (default: the general setting, 7) |  |
| `--note` | a label to recognise the invitation by, such as a name (at most 100 characters) |  |

## `tesserarr:invite:recovery`

Issue a recovery invitation: a link that adds a sign-in method to an existing account

### Aufruf

```text
tesserarr:invite:recovery [--days [DAYS]] [--] <username>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `username` | ja | the account that gets a new way in |

### Optionen

| Option | Beschreibung | Standard |
| --- | --- | --- |
| `--days` | how many days the link stays valid (default: the general setting, 7) |  |

## `tesserarr:invite:revoke`

Revoke an invitation that nobody has redeemed yet

### Aufruf

```text
tesserarr:invite:revoke <id>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `id` | ja | the id of the invitation (printed when it was issued) |

## `tesserarr:migrate`

Back up the database, then run the pending migrations

### Aufruf

```text
tesserarr:migrate [--lock-timeout [LOCK-TIMEOUT]]
```

### Optionen

| Option | Beschreibung | Standard |
| --- | --- | --- |
| `--lock-timeout` | seconds to wait for another tesserarr:migrate | `300` |

## `tesserarr:pii:rotate`

Re-encrypt personal data with TESSERARR_PII_KEY and recompute the blind index after a key change

### Aufruf

```text
tesserarr:pii:rotate [--dry-run]
```

### Optionen

| Option | Beschreibung | Standard |
| --- | --- | --- |
| `--dry-run` | count what would change, write nothing |  |

## `tesserarr:release-check`

Look up the newest release of Tesserarr on GitHub and remember it

### Aufruf

```text
tesserarr:release-check
```

## `tesserarr:role:assign`

Give an account a role (replaces its current role); keeps at least one active operator

### Aufruf

```text
tesserarr:role:assign <username> <role>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `username` | ja | the account, by username (never an e-mail address) |
| `role` | ja | operator, user or the name of an own role |

## `tesserarr:settings:get`

Show a general setting, its value in force and where the value comes from

### Aufruf

```text
tesserarr:settings:get [<key>]
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `key` | nein | a general setting such as ui.page_size; without it, all of them |

## `tesserarr:settings:set`

Change a general setting without the interface; logged with the console as origin

### Aufruf

```text
tesserarr:settings:set <key> <value>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `key` | ja | a general setting such as ui.page_size |
| `value` | ja | the new value |

## `tesserarr:token:issue`

Issue an API token for an account, capped at what the account holds; the token is printed once

### Aufruf

```text
tesserarr:token:issue [--name [NAME]] [--permission [PERMISSION]] [--preset [PRESET]] [--expires [EXPIRES]] [--] <username>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `username` | ja | the owner of the token, by username (never an e-mail address) |

### Optionen

| Option | Beschreibung | Standard |
| --- | --- | --- |
| `--name` | what the token is for, shown in the token list (1 to 100 characters) |  |
| `--permission` | a permission of the catalogue the token carries; repeat for several |  |
| `--preset` | a ready-made selection instead of --permission: arr (catalog.browse) or invitations (invitations.create) |  |
| `--expires` | days until the token expires, or "never" | `90` |

## `tesserarr:token:revoke`

Revoke an API token: it stops working at once

### Aufruf

```text
tesserarr:token:revoke <id>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `id` | ja | the id of the token (the number before the bar, shown in the token list) |

## `tesserarr:user:lock`

Lock an account: it is signed out and cannot sign in or use its tokens; keeps at least one active operator

### Aufruf

```text
tesserarr:user:lock <username>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `username` | ja | the account, by username (never an e-mail address) |

## `tesserarr:user:unlock`

Unlock a locked account

### Aufruf

```text
tesserarr:user:unlock <username>
```

### Argumente

| Argument | Pflicht | Beschreibung |
| --- | --- | --- |
| `username` | ja | the account, by username (never an e-mail address) |
