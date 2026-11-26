---
title: Sonarr und Radarr
description: Sonarr und Radarr anbinden, um Stammordner, Qualitätsprofile und Tags zu lesen.
---

Eine Verbindung braucht die Adresse der Instanz und ihren API-Schlüssel (Settings > General in Sonarr oder Radarr).
Tesserarr speichert den Schlüssel verschlüsselt und liest nur: Stammordner, Qualitätsprofile und Tags.

Ab 0.3.0 dreht sich die Richtung für deine Freunde um: Ihr Sonarr oder Radarr trägt Tesserarr als Newznab-Indexer und
SABnzbd-Download-Client ein und importiert, was über Syncthing ankommt.

**Stand:** Diese Verbindung kommt mit 0.1.0.
