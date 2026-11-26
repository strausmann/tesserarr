---
title: Fehlersuche
description: Erste Prüfungen, wenn etwas nicht funktioniert.
---

| Anzeichen | Prüfe |
| --- | --- |
| Der Stack startet nicht | Die Logs des Containers `migrate`: Eine fehlgeschlagene Sicherung hält ihn vor jeder Migration an. |
| `/up` antwortet mit `500` | Die Datenbank: Host, Port, Benutzer und Passwort unter [Konfiguration](/tesserarr/de/install/configuration/). |
| Ein Container endet sofort mit „needs TESSERARR_ROLE“ | Ein Prozess, der zur anderen Rolle gehört, zum Beispiel `worker` auf einem Satelliten. |
| Jede Anfrage scheint vom Proxy zu kommen | `TESSERARR_TRUSTED_PROXIES` nennt den Proxy, und der Proxy setzt `X-Forwarded-For`. |
| `413` bei einer API-Anfrage | Der Körper ist größer als 64 KiB, die Grenze von `/api/`. |

Diese Seite wächst mit jeder Version.
