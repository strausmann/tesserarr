---
title: Architektur
description: Eine Laravel-Anwendung, ein Image, eine Zentrale und freiwillige Satelliten.
---

Tesserarr ist eine Laravel-13-Anwendung (PHP 8.5) mit einer Svelte-5-Oberfläche über Inertia, einer
PostgreSQL-18-Datenbank und einem Image (Entscheidung 0012).

## Rollen

| Instanzrolle | Prozessrollen | Hält |
| --- | --- | --- |
| Zentrale | `web`, `worker`, `scheduler`, `reverb` | Nutzer, Einladungen, Rollen, Freigaben, das Protokoll, die API |
| Satellit | `satellite` | nichts: keine Datenbank, keine Queue, keine Sitzung |

Ein Satellit holt seine Aufträge über die Token-API bei der Zentrale ab und meldet die Ergebnisse (Entscheidung 0013).

## Zwei Eingänge, eine Rechteprüfung

| Pfad | Wer | Ausweis |
| --- | --- | --- |
| `/`, `/api/v1/app/*` | die Weboberfläche | Sitzungs-Cookie |
| `/api/v1/*` | Arr-Dienste, Skripte, Satelliten | API-Token |

Jeder Pfad hat genau eine Prüfung der Anmeldung (Entscheidung 0007). Jede Rechteentscheidung, auf beiden Pfaden, läuft
durch eine Funktion, den `Authorizer`: Er fragt, ob der Besitzer das Recht jetzt hat und, auf dem Token-Pfad, ob das Token
es trägt (Entscheidung 0016). Auch jede Freigabeprüfung des Katalogs läuft durch eine Funktion.

## Live-Aktualisierung

Ein Satellit meldet über die API; die Zentrale löst ein Ereignis aus; der WebSocket-Server (Laravel Reverb) schiebt es an
die Browser, die es sehen dürfen, nur über private Kanäle. Ein Satellit spricht nie mit dem WebSocket-Server. Fällt der
WebSocket aus, fragt die Seite in Abständen nach.
