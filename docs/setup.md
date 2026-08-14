---
icon: lucide/wrench
---

# Setup

Tesserarr ships as a single container image. It needs a place to sign users
in and, once its media-request half exists, an existing Syncthing folder
shared with the friend on the other end. This page covers the part that
already runs in 0.0.1: getting the container up, signed in, and reachable.

## Prerequisites

- Docker with Compose
- An OIDC provider you already run (e.g. Authentik, Microsoft Entra ID) —
  Tesserarr never handles a password itself, it only asks the provider "who
  is this" (see [ADR-0004](https://github.com/strausmann/tesserarr/blob/main/docs/adr/0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md))
- A domain or hostname you control, for `TESSERARR_BASE_URL`

## Deploy

Start from the base [`deploy/compose.yaml`](https://github.com/strausmann/tesserarr/blob/main/deploy/compose.yaml):

```yaml
services:
  tesserarr:
    image: ghcr.io/strausmann/tesserarr:0.0.1
    restart: unless-stopped
    ports:
      - "8080:8080"
    environment:
      TESSERARR_BASE_URL: "https://tesserarr.example.org"
      TESSERARR_DATA_DIR: "/data"
      TESSERARR_SESSION_SECRET: "CHANGE_ME"
      TESSERARR_TRUSTED_PROXIES: ""
      TESSERARR_OIDC_ISSUER_URL: "https://idp.example.org/application/o/tesserarr/"
      TESSERARR_OIDC_CLIENT_ID: "CHANGE_ME"
      TESSERARR_OIDC_CLIENT_SECRET: "CHANGE_ME"
      TESSERARR_SYNCTHING_API_URL: "http://syncthing:8384"
      TESSERARR_SYNCTHING_API_KEY: "CHANGE_ME"
    volumes:
      - ./data:/data
      - /mnt/media/outgoing:/data/outgoing
      - /mnt/media/income:/data/income
    healthcheck:
      test: ["CMD", "/usr/local/bin/tesserarr", "healthcheck"]
      interval: 30s
      timeout: 5s
      start_period: 5s
      retries: 3
```

Replace every `CHANGE_ME` yourself — Tesserarr never generates or ships a
default secret. `TESSERARR_SESSION_SECRET` needs at least 32 random bytes,
e.g. `openssl rand -hex 32`.

The repository also carries ready-made variants for the reverse proxy you
already run — [Caddy](https://github.com/strausmann/tesserarr/blob/main/deploy/compose.caddy.yaml),
[Traefik](https://github.com/strausmann/tesserarr/blob/main/deploy/compose.traefik.yaml),
or [Pangolin](https://github.com/strausmann/tesserarr/blob/main/deploy/compose.pangolin.yaml) —
and one that runs Syncthing alongside Tesserarr in the same stack
([`compose.syncthing.yaml`](https://github.com/strausmann/tesserarr/blob/main/deploy/compose.syncthing.yaml)).
Pick the one that matches your setup; they only differ in how traffic reaches
the container.

Bring it up:

```sh
docker compose -f deploy/compose.yaml up -d
```

## Sign in

Point your browser at `TESSERARR_BASE_URL`. You're redirected to your OIDC
provider; after signing in there, Tesserarr sets a session cookie and shows
the catalog page.

!!! note "0.0.1 has no invite management yet"

    Access to Tesserarr is invite-only by design — there is no
    self-registration (see [ADR-0004](https://github.com/strausmann/tesserarr/blob/main/docs/adr/0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md)).
    In 0.0.1 the catalog is intentionally empty and there is no interface yet
    to create an invitation from — that lands with the invite-management work
    tracked on the [roadmap](roadmap.md). Until then, this page will stop
    short of "your friend's first invite" and instead confirm that sign-in
    itself works end to end.

## Verify

`docker compose ps` should show the container `healthy` — the health check
runs `tesserarr healthcheck` against the local server, no network round trip
involved. If sign-in redirects back with an error, check that
`TESSERARR_OIDC_ISSUER_URL` matches your provider exactly (trailing slash and
all) and that the redirect URI registered with the provider points at
`TESSERARR_BASE_URL`.
