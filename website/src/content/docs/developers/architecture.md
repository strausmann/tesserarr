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

The two paths are two middleware groups (`bootstrap/app.php`), and every route belongs to exactly one of them or to
`web` (`tests/Feature/Security/RouteInventoryTest.php`):

| Group | Order |
| --- | --- |
| `session-api` (`/api/v1/app/*`) | cookies, session, `auth:web` (401), `X-Requested-With: tesserarr` (403 `missing_app_header`), CSRF token |
| `token-api` (`/api/v1/*`) | request body limit, `auth:sanctum` with the bearer token only. No cookie, session or CSRF middleware; the answer sets no cookie |

The token path has **no CSRF check, on purpose**: a browser never sends an `Authorization` header by itself, and a
foreign page cannot set one without a CORS preflight, which fails. A CSRF check there would tie the token path to
browser state and lock the arr apps out. The session path has two checks against cross-site requests besides
`SameSite`: the app's own header and Laravel's CSRF token.

Every route that changes something carries `->defaults('audited', true)` and writes an entry in the audit log. The few
that do not are listed with their reason in `tests/Support/Routes.php`: signing in and out, own preferences, and the
second factors until task 12.

## Live updates

A satellite reports through the API; the central instance broadcasts an event; the WebSocket server (Laravel Reverb)
pushes it to the browsers allowed to see it, on private channels only. A satellite never talks to the WebSocket server.
When the WebSocket is down, the page falls back to polling.
