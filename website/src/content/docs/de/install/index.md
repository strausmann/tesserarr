---
title: Installieren
description: Was Tesserarr braucht, wie seine Teile zusammenspielen und wie es weitergeht.
---

Tesserarr kommt als ein Image, `docker.io/bstrausmann/tesserarr`, für `linux/amd64` und `linux/arm64`. Dasselbe Image
führt jeden Teil aus; ein Befehl und die Variable `TESSERARR_ROLE` entscheiden, welchen.

**Stand:** 0.1.0 ist in Entwicklung. Vorabversionen (`0.1.0-rc.N`) gibt es zum Testen, nicht für den echten Betrieb.

## Die Teile

| Teil | Führt aus | Braucht |
| --- | --- | --- |
| Zentrale | Weboberfläche, API, Worker, Scheduler, WebSocket-Server | PostgreSQL 18, eine erreichbare Adresse |
| Satellit (freiwillig) | die Dateiarbeit neben dem Medienspeicher | ein Token der Zentrale; keine Datenbank |

Jeder Prozess der Zentrale ist ein eigener Container aus demselben Image: `web`, `worker`, `scheduler` und `reverb`. Ein
einmaliger Container `migrate` führt vorher `artisan tesserarr:migrate` aus und schreibt zuerst eine Sicherung der
Datenbank, wenn Migrationen anstehen.

## Nächste Schritte

1. [Erste Schritte](/tesserarr/de/install/getting-started/): der erste Start und die erste Einladung.
2. [Konfiguration](/tesserarr/de/install/configuration/): jede Umgebungsvariable.
3. [Reverse Proxy](/tesserarr/de/install/reverse-proxy/): HTTPS vor der Weboberfläche.
4. [Hinter CGNAT oder DS-Lite](/tesserarr/de/install/tunnels/): Erreichbarkeit über einen Tunnel.
5. [Satellit](/tesserarr/de/install/satellite/): Dateiarbeit auf einem anderen Rechner.
