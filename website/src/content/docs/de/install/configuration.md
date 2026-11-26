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
| `TESSERARR_PII_KEY` | Schlüssel für Personendaten (die E-Mail-Adressen und PGP-Schlüssel): 32 Bytes, roh oder als `base64:` gefolgt von 32 Bytes in Base64, zum Beispiel von `echo "base64:$(openssl rand -base64 32)"`. Muss sich von `APP_KEY` unterscheiden; ohne ihn startet die Zentrale nicht. Geheim. Siehe [Personendaten](/tesserarr/de/admin/privacy/). |
| `TESSERARR_BLIND_INDEX_KEY` | Schlüssel des Blindindex, über den eine Adresse gefunden wird: mindestens 32 Bytes, erzeugt wie `TESSERARR_PII_KEY`, aber ein anderer Wert. Ein Wechsel braucht `tesserarr:pii:rotate`. Geheim. |

## Schlüssel für Personendaten wechseln

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TESSERARR_PII_PREVIOUS_KEYS` | leer | Alte Werte von `TESSERARR_PII_KEY`, durch Komma getrennt; damit wird weiter gelesen, bis `tesserarr:pii:rotate` alles neu geschrieben hat. Geheim. |
| `TESSERARR_BLIND_INDEX_PREVIOUS_KEY` | leer | Der alte `TESSERARR_BLIND_INDEX_KEY`; solange er gesetzt ist, berechnet `tesserarr:pii:rotate` den Blindindex neu. Geheim. |

## Mail

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `MAIL_MAILER` | keiner (`log` nur in der lokalen Umgebung) | Der Mailer für Bestätigungslinks. Ohne Mailer lässt sich keine Konto-Adresse eintragen. Den `log`-Mailer, der Nachrichten samt Adressen ins Protokoll schreibt, gibt es nur mit `APP_ENV=local`. |

## Rollen und Netz

| Variable | Standard | Bedeutung |
| --- | --- | --- |
| `TESSERARR_ROLE` | `central` | `central` oder `satellite`. Jeder andere Wert beendet den Start. |
| `TESSERARR_TRUSTED_PROXIES` | leer | Reverse Proxies, deren Kopfzeile `X-Forwarded-For` vertraut wird, kommagetrennte Adressen oder Bereiche, zum Beispiel `192.0.2.10`. Leer vertraut keinem. |
| `SESSION_ENCRYPT` | `true` | Sitzungen werden mit `APP_KEY` verschlüsselt gespeichert. Wer es bei einer bestehenden Installation einschaltet, meldet einmal alle ab (die alten Sitzungen lassen sich nicht lesen). |
| `TESSERARR_TRUSTED_HOSTS` | leer | Hostnamen, auf die die Instanz neben dem Host von `APP_URL` antwortet, durch Komma getrennt. Anfragen mit einem anderen `Host`-Kopf werden abgelehnt; `localhost` und `127.0.0.1` bleiben für den Gesundheitscheck im Container erlaubt. Ein Gesundheitscheck, der den Container über seinen Namen aufruft, etwa der Ziel-Check von Pangolin (`healthcheck.hostname`), schickt diesen Namen als `Host`: Er gehört hierher (für einen Container `tesserarr`: `TESSERARR_TRUSTED_HOSTS=tesserarr`), sonst bekommt der Check 400. Links in Mails entstehen immer aus `APP_URL`. |

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
