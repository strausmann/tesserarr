---
title: Reverse proxy
description: Put HTTPS in front of the web interface and pass the real client address.
---

The image serves plain HTTP on port `8080`. In front of it belongs a reverse proxy that terminates HTTPS, for example
Caddy, Traefik or nginx.

- Forward the whole host to port `8080` of the `web` container, WebSocket upgrades included: live updates use them.
- Pass the client address in `X-Forwarded-For` and list the proxy in `TESSERARR_TRUSTED_PROXIES`, so the access log and
  the sign-in throttling see the real address ([configuration](/tesserarr/install/configuration/)).
- Set `APP_URL` to the public `https://` address.

Request bodies are limited twice: the image's web server refuses bodies above 2 MiB, the application refuses bodies
above 64 KiB on `/api/` with `413`. A proxy limit below these values breaks nothing; a higher one has no effect.
