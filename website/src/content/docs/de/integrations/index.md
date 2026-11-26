---
title: Integrationen
description: Die Dienste, mit denen Tesserarr sich verbindet, und was es mit jedem tut.
---

Du kannst mehrere Instanzen je Art anlegen, jede mit eigenem Namen, zum Beispiel zwei Sonarr-Instanzen für HD und 4K.
Jede Verbindung hat einen Test und zeigt ihre Version und ihren letzten Fehler.

| Dienst | 0.1.0 | Später |
| --- | --- | --- |
| [Sonarr und Radarr](/tesserarr/de/integrations/sonarr-and-radarr/) | Stammordner, Qualitätsprofile und Tags lesen | Sonarr und Radarr deiner Freunde nutzen Tesserarr als Indexer und Download-Client (0.3.0) |
| [Prowlarr](/tesserarr/de/integrations/prowlarr/) | Verbindungstest | Tesserarr als Indexer im Prowlarr deiner Freunde (0.3.0) |
| [Syncthing](/tesserarr/de/integrations/syncthing/) | Verbindungstest, Status | verwaltete Ordner und Geräte, Abholerkennung (0.2.0) |
| [Plex](/tesserarr/de/integrations/plex/) | die Bibliotheken lesen | Pfadvorschläge aus den Plex-Orten (0.2.0) |

Tesserarr hat keine Verbindung zu Overseerr oder Jellyseerr, in keiner Richtung: Anfragen laufen über Sonarr, Radarr und
Prowlarr.

**Stand:** Die Verbindungen kommen mit 0.1.0.
