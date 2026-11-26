---
title: Integrations
description: The services Tesserarr connects to and what it does with each of them.
---

You can add several instances of each kind, each with its own name, for example two Sonarr instances for HD and 4K.
Every connection has a test and shows its version and its last error.

| Service | 0.1.0 | Later |
| --- | --- | --- |
| [Sonarr and Radarr](/tesserarr/integrations/sonarr-and-radarr/) | read root folders, quality profiles and tags | your friends' Sonarr and Radarr use Tesserarr as indexer and download client (0.3.0) |
| [Prowlarr](/tesserarr/integrations/prowlarr/) | connection test | Tesserarr as an indexer in your friends' Prowlarr (0.3.0) |
| [Syncthing](/tesserarr/integrations/syncthing/) | connection test, status | managed folders and devices, pickup detection (0.2.0) |
| [Plex](/tesserarr/integrations/plex/) | read the libraries | path suggestions from Plex locations (0.2.0) |

Tesserarr has no link to Overseerr or Jellyseerr in either direction: requests run through Sonarr, Radarr and Prowlarr.

**Status:** connections arrive with 0.1.0.
