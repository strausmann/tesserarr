<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0007: Session for the interface, tokens for other systems, separate paths

- **Status:** accepted
- **Date:** 2026-08-13, revised 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** [ADR-0016](0016-single-authorization-function.md)
- **Related:** [ADR-0005](0005-go-core-separate-interface-one-image.md), [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md)

## Context

Two kinds of callers use Tesserarr: people in a browser, and other systems (Prowlarr, Sonarr, Radarr, scripts,
satellites) that send no browser cookies. One endpoint that accepts both a cookie and a token needs a branch that
decides which check applies; an error in that branch opens one path with the credential of the other. Cookies come with
cross-site request forgery; a header token does not, because a browser never sends an `Authorization` header by itself.

## Decision

1. The central instance keeps **all** credentials of other systems (media server, Syncthing, arr apps). The browser gets
   only a session cookie: `HttpOnly`, `Secure` over HTTPS, `SameSite`.
2. There are **two path prefixes**: `/api/v1/app/*` and the pages for the web interface (session), `/api/v1/*` for
   other systems (token). Each prefix has exactly **one** authentication check, without a shared branch.
3. **The token path never reads the session cookie.** A request to the token path with a valid session cookie and
   without an `Authorization` header is refused; a test proves it, and a symmetric one proves that the session path
   refuses a token.
4. The session path has a second, server-side protection against cross-site requests on top of `SameSite` (Laravel's
   CSRF token for the session routes).
5. The token path needs **no** CSRF protection: a header is never sent automatically by a browser, and a foreign page
   cannot set it without a CORS preflight that fails. This reason belongs in the code comment and the docs, so that
   nobody adds a "safety" CSRF check that would tie the token path to browser state and lock the arr apps out.
6. **Tokens carry permissions.** Each API token holds a selection from the one permission catalogue that roles use as
   well, with presets ("arr connection", "satellite", "create invitations"). No token can do more than its owner; the
   path decides *how* a caller proves who they are, the permissions decide *what* they may do. Every permission
   decision goes through one function ([ADR-0016](0016-single-authorization-function.md)).

## Consequences

- The token path is immune to cross-site request forgery by construction.
- Credentials of other systems never reach the browser; a script injection in the interface finds none.
- The separation is testable, not only a convention.
- Two checks to maintain. A new endpoint registered under the wrong prefix is still a matter of care; the route files
  (`routes/api_app.php`, `routes/api.php`) and the published OpenAPI document, which contains only `/api/v1`, make it
  visible.
- The central instance holds a session store besides the tokens; when it fails, browsers are affected while the arr
  apps keep working.
- Keeping every credential in one place moves the damage of a server-side flaw from one user to all of them; a central,
  well-tested place was preferred over many scattered, weaker ones.
- `Secure` cookies need HTTPS; development without TLS needs the documented exception.

## History

Recorded for the Go version (tag `archive/go-0.0.2`). Revised with the move to Laravel: tokens carry permissions from a
shared catalogue, and the permission check is one function (point 6, [ADR-0016](0016-single-authorization-function.md)).

## References

- OWASP Session Management Cheat Sheet: <https://cheatsheetseries.owasp.org/cheatsheets/Session_Management_Cheat_Sheet.html>
