---
title: Allgemeine Einstellungen
description: Name, Sprache, Zeitzone und Vorgaben der Instanz in der Oberfläche, und was in der Umgebung bleibt.
---

Unter *Verwaltung, Allgemein* legt der Betreiber fest, was zur Instanz als Ganzes gehört. Jede Änderung steht im
[Protokoll](/tesserarr/de/admin/audit-log/) mit dem Schlüssel der Einstellung, nie mit ihrem Wert. Die Seite braucht
das Recht `settings.manage`.

## Was du einstellen kannst

| Einstellung | Schlüssel | Vorgabe | Bedeutung |
| --- | --- | --- | --- |
| Anzeigename | `instance.display_name` | `Tesserarr` | Erscheint in der Kopfzeile und auf der Anmeldeseite. 1 bis 60 Zeichen, keine Steuerzeichen. |
| Standardsprache | `locale.default` | `en` | `de` oder `en`. Gilt für Personen, deren Profil und Browser keine Sprache nennen. |
| Zeitzone | `time.zone` | `UTC` | Ein IANA-Name wie `Europe/Berlin`. |
| Listengröße beim Laden | `ui.page_size` | `50` | `25`, `50` oder `100` Einträge pro Abruf, im Protokoll und im Katalog. |
| Standard-Thema | `ui.theme_default` | `system` | `system`, `light` oder `dark`, bis jemand unter *Konto* ein eigenes wählt. |
| Gültigkeit neuer Einladungen | `invitations.default_days` | `7` | 1 bis 90 Tage. Beim Einladen änderbar. |
| Aufbewahrung des Protokolls | `audit.retention_days` | `365` | 30 bis 3650 Tage. |
| Sicherungen behalten | `backups.keep` | `5` | 1 bis 50. `tesserarr:migrate` behält so viele Sicherungen. |

Ein ungültiger Wert wird mit Nennung des Feldes abgelehnt, und vom Formular wird nichts gespeichert. Ein Speichern ohne
Änderung hinterlässt keinen Protokolleintrag.

## Notfall-Überschreibung per Umgebungsvariable

Jede Einstellung hat eine Umgebungsvariable, die sie überschreibt. Sie ist für den Notfall gedacht, etwa eine falsche
Zeitzone, mit der du Datumsangaben nicht mehr lesen kannst. Eine gesetzte Variable gewinnt gegen den gespeicherten
Wert, und die Seite zeigt das Feld dann gesperrt: „Von Umgebung überschrieben. Gesetzt über `TZ`.“ Ein gesperrtes Feld
lässt sich nicht ändern. Entferne die Variable und starte neu, um die Einstellung wieder in der Oberfläche zu bearbeiten.

| Einstellung | Variable |
| --- | --- |
| `instance.display_name` | `TESSERARR_DISPLAY_NAME` |
| `locale.default` | `TESSERARR_DEFAULT_LOCALE` |
| `time.zone` | `TZ` |
| `ui.page_size` | `TESSERARR_PAGE_SIZE` |
| `ui.theme_default` | `TESSERARR_THEME_DEFAULT` |
| `invitations.default_days` | `TESSERARR_INVITATION_DAYS` |
| `audit.retention_days` | `TESSERARR_AUDIT_RETENTION_DAYS` |
| `backups.keep` | `TESSERARR_BACKUP_KEEP` |

Eine leere Variable gilt als nicht gesetzt. Eine Variable mit einem Wert, den die Einstellung nicht annimmt, wird
ignoriert, und die Seite sagt es; strenger ist nur `TESSERARR_BACKUP_KEEP`: `tesserarr:migrate` bricht ab, wenn sie
keine ganze Zahl von 1 bis 50 ist.

## Was in der Umgebung bleibt

Die Plattform-URL (`APP_URL`, sie bestimmt die Passkey-Domain), die vertrauenswürdigen Proxy-Adressen
(`TESSERARR_TRUSTED_PROXIES`), der Datenbankzugang, die Schlüssel und die Rolle sind keine Einstellungen. Die Karte
*Über die Umgebung gesetzt* zeigt sie nur lesend: URL, Proxys und Rolle mit Wert, Schlüssel und Datenbank-Passwort nur
als *gesetzt* oder *nicht gesetzt*. Die Karte zeigt auch, wie Tesserarr deine eigene Anfrage sieht (Client-Adresse,
Proxy-Adresse), das hilft beim Prüfen der Proxy-Liste. Siehe [Konfiguration](/tesserarr/de/install/configuration/).

## Auf der Kommandozeile

```bash
php artisan tesserarr:settings:get ui.page_size   # geltender Wert und seine Herkunft
php artisan tesserarr:settings:get                # alle Einstellungen
php artisan tesserarr:settings:set ui.page_size 100
```

Die Konsole nutzt dieselben Regeln wie die Seite und wird mit der Konsole als Herkunft protokolliert. Eine Einstellung,
die eine Umgebungsvariable überschreibt, wird auch hier abgelehnt: entferne zuerst die Variable.
