---
title: Konfiguration
description: Jede Umgebungsvariable von Tesserarr, ihr Standardwert und was sie bewirkt.
---

Tesserarr liest seine Einstellungen aus der Umgebung. Einstellungen, die in die Weboberfläche gehören (Anmeldewege, die
MFA-Regel, Mail), setzt du dort, nicht hier. Jede Version ergänzt die Variablen ihrer Funktionen auf dieser Seite.

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
| `TESSERARR_BACKUP_KEEP` | `5` | Wie viele Sicherungen bleiben, 1 bis 50. |
| `TESSERARR_PG_DUMP` | `pg_dump` | Das Programm `pg_dump` für die Sicherung. |
| `TESSERARR_ACCESS_LOG` | `php://stdout` | Wohin das Zugriffsprotokoll geht (Format „combined“). |

## Aktualisierungen

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TESSERARR_RELEASE_CHECK` | `true` | Alle sechs Stunden bei den GitHub-Releases von Tesserarr nachfragen, ob es eine neuere Version gibt; der Betreiber sieht es unter „Über“. `false` sendet nichts. Siehe [Version und Aktualisierungen](/tesserarr/de/using/updates/). |
| `TESSERARR_RELEASE_CHECK_TIMEOUT` | `5` | Sekunden, die die Prüfung auf eine Antwort wartet. |
| `TESSERARR_RELEASE_CHECK_URL` | das neueste Release von `strausmann/tesserarr` auf `api.github.com` | Wo die Prüfung fragt, für einen Spiegel, der wie die Releases-API von GitHub antwortet. |
