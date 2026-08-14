---
icon: lucide/shield-check
---

# Forward-auth sign-in

If you already run a guard in front of your services — Pangolin, Authentik, tinyauth, Authelia
or oauth2-proxy — Tesserarr can trust ITS sign-in instead of asking your friends to sign in a
second time. This page covers turning it on, once you already have it running behind that guard.

OIDC sign-in (see [Setup](setup.md)) keeps working side by side — enabling forward-auth does not
turn it off.

## How it works

1. Your guard authenticates the visitor and forwards the request to Tesserarr with identity
   headers attached (who they are, optionally their name, email and role).
2. Tesserarr checks the request comes from a network you named as trusted AND carries a shared
   secret only your guard knows — see [Trust](#trust) below. Without both, the headers are never
   even read.
3. A known visitor gets a session. An unknown one only gets an account with a valid, unredeemed
   invitation — exactly the same rule OIDC sign-in follows (see
   [ADR-0004](https://github.com/strausmann/tesserarr/blob/main/docs/adr/0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md)).

## Trust

Two things, both required:

- **`TESSERARR_TRUSTED_PROXIES`** — the CIDR range your guard's network segment lives in, e.g.
  `172.20.0.0/16` for a Docker network. Tesserarr checks the connecting IP against this — an
  empty value trusts nothing, the safe default.
- **`TESSERARR_FORWARD_AUTH_SHARED_SECRET`** — a random value only your guard and Tesserarr know.
  Generate one with `openssl rand -hex 32`. Your guard adds it as a static header named
  `X-Tesserarr-Forward-Auth-Secret` on every request it forwards (Pangolin: the resource's
  `headers` field).

Tesserarr **refuses to start** if `TESSERARR_FORWARD_AUTH_ENABLED` is set without a shared secret
— see [Setup](setup.md) for what a failed startup looks like in the container logs.

!!! warning "Keep the shared secret out of your reverse proxy's access log"

    The shared secret rides along as a header on every single request your guard forwards — it
    is not a one-time login token, it is a standing credential. If your reverse proxy logs
    request headers (Traefik's access log supports a `headers.names` field for this; nginx needs
    a custom `log_format` to capture headers at all), the secret ends up in plain text in a file
    that is usually far less protected than the configuration it came from — and from there it
    travels into every place that access log gets shipped: log aggregation, backups, anything
    that reads "just the access log". Before you enable header logging on the proxy in front of
    Tesserarr, check whether `X-Tesserarr-Forward-Auth-Secret` (or whatever header name you gave
    the secret) is excluded from it. If it already is not, treat that the same way you would a
    leaked password — rotate `TESSERARR_FORWARD_AUTH_SHARED_SECRET` and update your guard's
    configuration with the new value.

## Per-guard configuration

Set `TESSERARR_FORWARD_AUTH_PRESET` to one of the values below — it fills in the header names your
guard actually sends. Override any single one with `TESSERARR_FORWARD_AUTH_HEADER_SUBJECT`,
`_USERNAME`, `_NAME`, `_EMAIL` or `_ROLE` if your setup differs from the default.

| Preset | Stable identity header | Notes |
|---|---|---|
| `pangolin` | `Remote-User-Id` | Also sends `Remote-Role` — Tesserarr's role system works out of the box. |
| `authentik` | `X-authentik-uid` | The OIDC `sub` claim. No role header by default — set `TESSERARR_FORWARD_AUTH_HEADER_ROLE` yourself if you want to map a group to it. |
| `tinyauth` | `Remote-Sub` | The OIDC `sub` claim. |
| `authelia` | **none** | See [A stable identity is not always available](#a-stable-identity-is-not-always-available). |
| `oauth2-proxy` | **none** | Same warning applies. |

<a id="a-stable-identity-is-not-always-available"></a>
!!! warning "A stable identity is not always available"

    Pangolin, Authentik and tinyauth send an identity value that survives a rename in the guard.
    **Authelia and oauth2-proxy do not** — their forward-auth headers carry only a username or
    email, both of which can change.

    If you use one of these two, you MUST set `TESSERARR_FORWARD_AUTH_HEADER_SUBJECT` yourself
    (to `Remote-User` or `Remote-Email` for Authelia, `X-Auth-Request-Preferred-Username` or
    `X-Auth-Request-Email` for oauth2-proxy) — Tesserarr refuses to start otherwise. And know what
    happens next: if that value ever changes, the person is treated as a brand-new visitor. They
    need a fresh invitation, and their old account is orphaned (their history, quota and requests
    stay where they were — nothing is lost, it just is not the account they will sign into
    anymore). Fix it with:

    ```sh
    docker exec -it tesserarr tesserarr user remap <old-value> <new-value>
    ```

## Roles

`TESSERARR_ROLE_MAP` names roles and what they grant, e.g.:

```
TESSERARR_ROLE_MAP=admin:administer+request+libraries=*,familie:request+libraries=*,gast:request+libraries=filme
TESSERARR_DEFAULT_ROLE=familie
```

A guard's role header (only Pangolin sends one by default — see the table above) always wins when
present, even over a role you set by hand. If your guard sends no role header, set one per person
with:

```sh
docker exec -it tesserarr tesserarr user promote <email> <role>
```

This is also how you get your FIRST administrator, before any guard is configured at all — sign in
once (via OIDC or forward-auth), then promote that account. The promotion takes effect on that
account's NEXT sign-in, not immediately — Tesserarr only ever recomputes the role someone is
actually granted at the moment they sign in.

## Invitation links

`tesserarr invite create` prints an OIDC-style link always, and a second, forward-auth-style link
(`.../api/v1/app/login/forward-auth?invite=<token>`) whenever `TESSERARR_FORWARD_AUTH_ENABLED` is
set. Hand the one matching how the recipient will actually reach Tesserarr.
