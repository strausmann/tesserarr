---
title: Install
description: What Tesserarr needs, how its parts fit together and where to go next.
---

Tesserarr ships as one image, `docker.io/bstrausmann/tesserarr`, for `linux/amd64` and `linux/arm64`. The same image
runs every part; a command and the variable `TESSERARR_ROLE` decide which.

**Status:** 0.1.0 is in development. Preview images (`0.1.0-rc.N`) exist for testing, not for real use.

## The parts

| Part | Runs | Needs |
| --- | --- | --- |
| Central instance | web interface, API, worker, scheduler, WebSocket server | PostgreSQL 18, a reachable address |
| Satellite (optional) | the file work next to the media storage | a token of the central instance; no database |

Each process of the central instance is its own container from the same image: `web`, `worker`, `scheduler` and
`reverb`. A one-off `migrate` container runs `artisan tesserarr:migrate` before them and writes a database backup first
when migrations are pending.

## Next steps

1. [Getting started](/tesserarr/install/getting-started/): the first start and the first invitation.
2. [Configuration](/tesserarr/install/configuration/): every environment variable.
3. [Reverse proxy](/tesserarr/install/reverse-proxy/): HTTPS in front of the web interface.
4. [Behind CGNAT or DS-Lite](/tesserarr/install/tunnels/): reachability through a tunnel.
5. [Satellite](/tesserarr/install/satellite/): file work on another machine.
