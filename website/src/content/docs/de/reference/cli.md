---
title: Befehlszeile
description: Jeder tesserarr-Befehl von artisan, mit seinen Argumenten und Optionen.
---

<!-- Diese Seite wird von `scripts/cli_reference.py` aus `php artisan list --format=json` erzeugt; ändere die Beschreibungen in `app/Console/Commands/`, nicht hier. -->

Führe einen Befehl über den Einstiegspunkt `artisan` des Images aus, zum Beispiel `docker compose run --rm --no-deps web artisan list tesserarr`. Die Beschreibungen stammen aus dem Code und sind englisch.

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
