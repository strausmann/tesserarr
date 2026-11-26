---
title: Architecture
description: One Laravel application, one image, a central instance and optional satellites.
---

Tesserarr is one Laravel 13 application (PHP 8.5) with a Svelte 5 interface over Inertia, one PostgreSQL 18 database
and one image (decision record 0012).

## Roles

| Instance role | Process roles | Holds |
| --- | --- | --- |
| Central | `web`, `worker`, `scheduler`, `reverb` | users, invitations, roles, shares, the audit log, the API |
| Satellite | `satellite` | nothing: no database, no queue, no session |

A satellite pulls its jobs from the central instance over the token API and reports the results (decision record 0013).

## Two ways in, one permission check

| Path | Who | Credential |
| --- | --- | --- |
| `/`, `/api/v1/app/*` | the web interface | session cookie |
| `/api/v1/*` | arr apps, scripts, satellites | API token |

Each path has exactly one authentication check (decision record 0007). Every permission decision, on either path, goes
through one function, the `Authorizer`: it asks whether the owner holds the permission now and, on the token path,
whether the token carries it (decision record 0016). Every grant check of the catalogue goes through one function as well.

## Live updates

A satellite reports through the API; the central instance broadcasts an event; the WebSocket server (Laravel Reverb)
pushes it to the browsers allowed to see it, on private channels only. A satellite never talks to the WebSocket server.
When the WebSocket is down, the page falls back to polling.
