---
title: Troubleshooting
description: First checks when something does not work.
---

| Symptom | Check |
| --- | --- |
| The stack does not start | Logs of the `migrate` container: a failed backup stops it before any migration. |
| `/up` answers `500` | The database: host, port, user and password in [configuration](/tesserarr/install/configuration/). |
| A container stops at once with "needs TESSERARR_ROLE" | A process that belongs to the other role, for example `worker` on a satellite. |
| Every request appears to come from the proxy | `TESSERARR_TRUSTED_PROXIES` lists the proxy and the proxy sets `X-Forwarded-For`. |
| `413` on an API request | The body is above 64 KiB, the limit of `/api/`. |

This page grows with every release.
