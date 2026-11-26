---
title: Konfiguration
description: Jede Umgebungsvariable von Tesserarr, ihr Standardwert und was sie bewirkt.
---

Tesserarr liest seine Konfiguration aus der Umgebung. Einstellungen, die in die Weboberfläche gehören (Anmeldewege, die
MFA-Regel, Mail und die [allgemeinen Einstellungen](/tesserarr/de/admin/settings/) wie Name, Sprache und Zeitzone),
setzt du dort, nicht hier. Jede Version ergänzt die Variablen ihrer Funktionen auf dieser Seite.

## Pflichtangaben

| Variable | Bedeutung |
| --- | --- |
| `APP_KEY` | Schlüssel für Sitzungen und verschlüsselte Werte. Erzeuge ihn einmal mit `artisan key:generate --show` und bewahre ihn auf: ohne ihn lassen sich verschlüsselte Werte nicht mehr lesen. Geheim. |
| `APP_URL` | Die Adresse, unter der die Weboberfläche geöffnet wird, zum Beispiel `https://media.example.org`. |
| `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` | Die PostgreSQL-18-Datenbank der Zentrale. `DB_PASSWORD` ist geheim. |

## Rollen und Netz

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TESSERARR_ROLE` | `central` | `central` oder `satellite`. Jeder andere Wert beendet den Start. |
| `TESSERARR_TRUSTED_PROXIES` | leer | Reverse Proxies, deren Kopfzeile `X-Forwarded-For` vertraut wird, kommagetrennte Adressen oder Bereiche, zum Beispiel `192.0.2.10`. Leer vertraut keinem. |

## Sicherungen und Protokolle

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TESSERARR_BACKUP_DIR` | `/var/lib/tesserarr/backups` | Wohin `tesserarr:migrate` vor dem Migrieren die Sicherung mit `pg_dump` schreibt. Häng hier ein Volume ein. |
| `TESSERARR_BACKUP_KEEP` | `5` | Wie viele Sicherungen bleiben, 1 bis 50. Die Einstellung *Sicherungen behalten* der Oberfläche; die Variable überschreibt sie, nur für den Notfall. |
| `TESSERARR_PG_DUMP` | `pg_dump` | Das Programm `pg_dump` für die Sicherung. |
| `TESSERARR_ACCESS_LOG` | `php://stdout` | Wohin das Zugriffsprotokoll geht (Format „combined“). |

## Notfall-Überschreibungen der allgemeinen Einstellungen

Diese Variablen überschreiben die [allgemeinen Einstellungen](/tesserarr/de/admin/settings/) der Oberfläche. Lass sie im
Normalbetrieb ungesetzt: Eine gesetzte Variable sperrt ihr Feld in der Oberfläche.

| Variable | Überschreibt |
| --- | --- |
| `TESSERARR_DISPLAY_NAME` | Anzeigename |
| `TESSERARR_DEFAULT_LOCALE` | Standardsprache, `de` oder `en` |
| `TZ` | Zeitzone, ein IANA-Name |
| `TESSERARR_PAGE_SIZE` | Listengröße, `25`, `50` oder `100` |
| `TESSERARR_THEME_DEFAULT` | Standard-Thema, `system`, `light` oder `dark` |
| `TESSERARR_INVITATION_DAYS` | Gültigkeit neuer Einladungen, 1 bis 90 |
| `TESSERARR_AUDIT_RETENTION_DAYS` | Aufbewahrung des Protokolls, 30 bis 3650 |

## Aktualisierungen

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TESSERARR_RELEASE_CHECK` | `false` | Nur auf Wunsch. `true`: Die Zentrale fragt alle sechs Stunden bei den GitHub-Releases von Tesserarr nach, ob es eine neuere Version gibt, nur mit dem User-Agent `Tesserarr`; der Betreiber sieht es unter „Über“. `false` sendet nichts. Siehe [Version und Aktualisierungen](/tesserarr/de/using/updates/). |
| `TESSERARR_RELEASE_CHECK_TIMEOUT` | `5` | Sekunden, die die Prüfung auf eine Antwort wartet. |
| `TESSERARR_RELEASE_CHECK_URL` | das neueste Release von `strausmann/tesserarr` auf `api.github.com` | Wo die Prüfung fragt, für einen Spiegel, der wie die Releases-API von GitHub antwortet. |
