---
title: Hinter CGNAT oder DS-Lite
description: Die Zentrale über einen Tunnel erreichbar machen, wenn dein Anschluss keine öffentliche IPv4-Adresse hat.
---

Freunde, ihre Arr-Dienste und deine Satelliten müssen die Zentrale erreichen. Syncthing selbst kommt über seine eigenen
Relays durch, die Weboberfläche und die API brauchen aber eine erreichbare Adresse. Tesserarr hat kein eigenes Relay
(Entscheidung 0014): Hat dein Anschluss keine öffentliche IPv4-Adresse, setz einen Tunnel davor.

| Tunnel | Passt, wenn |
| --- | --- |
| Pangolin | du einen eigenen Tunnel-Server auf einer kleinen Cloud-VM betreibst |
| Cloudflare Tunnel | deine Domain bei Cloudflare liegt |
| Tailscale Funnel | dein Homelab schon in einem Tailnet ist |

Satelliten nutzen dieselbe öffentliche Adresse wie alle anderen. Egal welcher Tunnel: Die Regeln für den
[Reverse Proxy](/tesserarr/de/install/reverse-proxy/) gelten weiter, also HTTPS, WebSocket-Upgrades und eine vertraute Kopfzeile
`X-Forwarded-For`.

**Stand:** Anleitungen Schritt für Schritt für jeden Tunnel kommen mit 0.1.0.
