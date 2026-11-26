---
title: Behind CGNAT or DS-Lite
description: Make the central instance reachable through a tunnel when your connection has no public IPv4 address.
---

Friends, their arr apps and your satellites must reach the central instance. Syncthing itself gets through on its own
relays, but the web interface and the API need a reachable address. Tesserarr has no relay of its own (decision record
0014): when your connection has no public IPv4 address, put a tunnel in front of it.

| Tunnel | Fits when |
| --- | --- |
| Pangolin | you run your own tunnel server on a small cloud VM |
| Cloudflare Tunnel | your domain is at Cloudflare |
| Tailscale Funnel | your homelab is in a tailnet already |

Satellites use the same public address as everyone else. Whatever the tunnel, keep the [reverse proxy](/tesserarr/install/reverse-proxy/)
rules: HTTPS, WebSocket upgrades and a trusted `X-Forwarded-For`.

**Status:** step-by-step guides for each tunnel arrive with 0.1.0.
