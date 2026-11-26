---
title: Satellite
description: Run the file work next to your media storage, without a database and without an open port.
---

A satellite is the same image with `TESSERARR_ROLE=satellite`. It runs where the media lie, for example on a NAS next to
Syncthing, and does the file work the central instance decides on: hardlinks into the outgoing folder (from 0.2.0) and
read-only checks.

- It **knows no users and no shares**. It gets narrow jobs that are decided already.
- It **dials out**. It fetches its jobs from the central instance; nothing on its host listens for the centre.
- It has **no database and no queue**, only its own revocable token with the permission `satellite.execute`.
- It checks **itself** that every resolved path lies inside a media share it was given, as a second, independent barrier.

A compromised satellite costs that one machine, not the user management.

**Status:** registration, heartbeat and a harmless probe job arrive with 0.1.0. The variables of the satellite are added
to [configuration](/tesserarr/install/configuration/) then.
