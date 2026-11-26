---
title: Reverse Proxy
description: HTTPS vor die Weboberfläche setzen und die echte Absenderadresse durchreichen.
---

Das Image liefert reines HTTP auf Port `8080`. Davor gehört ein Reverse Proxy, der HTTPS abschließt, zum Beispiel Caddy,
Traefik oder nginx.

- Leite den ganzen Host auf Port `8080` des Containers `web` weiter, WebSocket-Upgrades eingeschlossen: die
  Live-Aktualisierung nutzt sie.
- Reiche die Absenderadresse in `X-Forwarded-For` durch und trag den Proxy in `TESSERARR_TRUSTED_PROXIES` ein, damit das
  Zugriffsprotokoll und die Anmelde-Drosselung die echte Adresse sehen ([Konfiguration](/tesserarr/de/install/configuration/)).
- Setze `APP_URL` auf die öffentliche `https://`-Adresse.

Anfragekörper sind zweifach begrenzt: der Webserver im Image weist Körper über 2 MiB ab, die Anwendung Körper über 64 KiB
auf `/api/` mit `413`. Eine Proxy-Grenze darunter schadet nicht; eine höhere bewirkt nichts.
