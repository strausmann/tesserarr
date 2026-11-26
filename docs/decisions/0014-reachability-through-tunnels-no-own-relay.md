<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0014: Reachability through tunnels, no own relay

- **Status:** accepted
- **Date:** 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0013](0013-satellite-pull-model.md)

## Context

Friends (browser and arr apps) and satellites have to reach the central instance. Many home connections have no public
IPv4 address (carrier-grade NAT, DS-Lite). Syncthing itself gets through on its own relays, the web interface and the
API do not. Tesserarr could ship a relay of its own, or rely on the tunnels homelabs use already.

## Decision

1. Tesserarr **assumes a reachable central instance** and builds **no relay of its own**.
2. Operators behind carrier-grade NAT or DS-Lite put a tunnel in front of it. The documentation describes Pangolin,
   Cloudflare Tunnel and Tailscale Funnel.
3. Satellites use the same public address as everyone else.

## Consequences

- No relay to build, secure and operate; the tunnels are maintained by others and already run in many homelabs.
- Operators without any tunnel cannot offer Tesserarr to friends outside their network.
- The reverse proxy rules (HTTPS, WebSocket upgrades, trusted `X-Forwarded-For`) apply to every tunnel.

Not to be confused with an own **Syncthing** relay, which is a separate, optional part of 0.2.0 for the file transport.
