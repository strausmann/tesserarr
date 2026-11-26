---
title: Erste Schritte
description: Die Zentrale starten, die erste Einladung erzeugen und sich anmelden.
---

**Stand:** Die Compose-Datei für eine echte Installation und die Einrichtungs-Checkliste kommen mit 0.1.0. Diese Seite
beschreibt die Schritte, die schon feststehen.

## Was du brauchst

- Docker Engine mit dem Compose-Plugin.
- PostgreSQL 18, in einem Container neben Tesserarr oder anderswo.
- Eine Adresse, die deine Freunde erreichen, meist über einen [Reverse Proxy](/tesserarr/de/install/reverse-proxy/) oder einen
  [Tunnel](/tesserarr/de/install/tunnels/).

## Erster Start

1. Erzeuge einmal den Anwendungsschlüssel und bewahre ihn auf: `docker run --rm docker.io/bstrausmann/tesserarr:<version> artisan key:generate --show`.
   Trag ihn als `APP_KEY` in die Umgebung ein.
2. Setze die Datenbank-Variablen und `APP_URL`, siehe [Konfiguration](/tesserarr/de/install/configuration/).
3. Starte den Stack. Der Container `migrate` läuft zuerst; `web`, `worker` und `scheduler` warten, bis er fertig ist.
4. Prüfe den Gesundheitspfad: `/up` antwortet mit `200`, wenn die Instanz und ihre Datenbank laufen.

## Das erste Konto

Es gibt keine Registrierungsseite. Die erste Einladung kommt von der Befehlszeile, jede weitere aus der Weboberfläche oder
über die API. Der Befehl dafür steht in der [Befehlszeilen-Referenz](/tesserarr/de/reference/cli/), sobald es ihn gibt.
