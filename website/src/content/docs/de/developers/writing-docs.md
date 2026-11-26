---
title: Doku schreiben
description: Englisch und Deutsch zusammen, relative Links und keine Daten einer echten Installation.
---

Die Seite liegt in `website/` (Astro Starlight) und wird von `main` auf GitHub Pages veröffentlicht. Pull Requests bauen
sie nur.

- **Zwei Sprachen, ein Pull Request.** Englisch liegt in der Wurzel von `website/src/content/docs/`, Deutsch unter `de/`
  mit demselben Dateinamen. Eine Seite in nur einer Sprache lässt die Prüfung auf Sprachgleichheit scheitern
  (`node website/scripts/check-locale-parity.mjs`).
- **Jede Funktion kommt mit ihrer Seite**, im Pull Request, der sie baut.
- **Interne Links beginnen mit dem Basispfad** (`/tesserarr/de/install/configuration/`), mit dem Schrägstrich am Ende.
  Die Seite liegt auf GitHub Pages unter `/tesserarr/`, bis sie eine Domain hat. `starlight-links-validator` lässt den Bau
  bei einem kaputten internen Link scheitern, und bei einem relativen, den er nicht prüfen kann.
- **Erzeugte Seiten.** `reference/cli.md` kommt aus `scripts/cli_reference.py`, die API-Referenz aus
  `website/openapi/openapi.json`. Ändere den Code, nicht die Seite.
- **Keine echten Daten.** Die Seite ist öffentlich. Nimm `media.example.org`, die Dokumentationsbereiche `192.0.2.0/24`,
  `198.51.100.0/24` und `203.0.113.0/24` und die Namen `alice` und `bob`. Der Job `real-data scan`
  (`scripts/privacy_scan.py`) schlägt bei privaten Adressen, Rechnernamen einer Installation und persönlichen
  E-Mail-Adressen fehl.

Lokal bauen und prüfen (Node 22):

```bash
npm --prefix website ci
npm --prefix website run build
node --test website/scripts/*.test.mjs && node website/scripts/check-locale-parity.mjs
python3 scripts/privacy_scan.py website/src website/public website/openapi
```
