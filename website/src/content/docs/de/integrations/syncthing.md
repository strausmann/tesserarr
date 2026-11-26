---
title: Syncthing
description: Deine Syncthing-Instanz anbinden, den Transport jeder Datei.
---

Tesserarr spricht mit Syncthing über seine REST-API mit dem API-Schlüssel der Instanz. In 0.1.0 testet es die Verbindung
und zeigt den Status. Ab 0.2.0 verwaltet es je Medienfreigabe und Freund einen Ordner (bei dir nur senden, beim Freund nur
empfangen), misst den Fortschritt je Gerät und Ordner und erkennt, wann ein Titel abgeholt wurde.

Syncthing erreicht deine Freunde direkt oder über seine Relays; du öffnest ihnen keinen Port deines eigenen Syncthing.

**Stand:** Diese Verbindung kommt mit 0.1.0.
