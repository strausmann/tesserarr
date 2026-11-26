---
title: Sonarr and Radarr
description: Connect Sonarr and Radarr to read root folders, quality profiles and tags.
---

A connection needs the address of the instance and its API key (Settings > General in Sonarr or Radarr). Tesserarr
stores the key encrypted and only reads: root folders, quality profiles and tags.

From 0.3.0 the direction turns around for your friends: their Sonarr or Radarr adds Tesserarr as a Newznab indexer and
an SABnzbd download client, and imports what arrives over Syncthing.

**Status:** this connection arrives with 0.1.0.
