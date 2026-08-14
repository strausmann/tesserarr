# Bericht: Doku-Website (Teil B, Aufgabe 9)

## Auftrag

Die Zensical-Doku-Website aufbauen (Startseite, Einrichtung, Roadmap-Gerüst) und zuvor die im
Konzept unter Vorbehalt stehende Behauptung prüfen, ob Zensical für die Startseite über die
`index.md`-Konvention hinaus einen eigenen Mechanismus kennt.

## Vorgefundener Zustand

Der Klon `b-docs` wurde von der Spitze der bereits zusammengeführten Integrationshistorie
angelegt (`feat/0.0.1-geruest`), nicht von einem isolierten Punkt vor der Doku-Arbeit. Darin
war die Doku bereits vollständig vorhanden — Commit `3c25c0b "feat(docs): add zensical
documentation site with home, setup and roadmap"` (plus zwei ADR-Commits) war schon Teil der
Historie, sichtbar am `Merge remote-tracking branch 'rb9/b-docs'`-Merge in derselben Kette.
`docs/index.md`, `docs/setup.md`, `docs/roadmap.md`, `docs/adr/` und `zensical.toml` lagen
bereits fertig vor — ich habe sie gelesen (wie im Auftrag verlangt) statt eine leere Fläche
anzunehmen, und meine Aufgabe war damit **Prüfung + Verifikation**, nicht Neubau.

## Schritt 1: Zensical-Startseiten-Konvention — Prüfergebnis

**Geprüft direkt gegen die Primärdokumentation**, zwei Seiten von `zensical.org`:

- `https://zensical.org/compatibility/configuration/`
- `https://zensical.org/compatibility/features/`

**Ergebnis: Zensical kennt keinen eigenen Mechanismus für eine Startseite.** Beide Seiten
nennen nichts zu „home"/„homepage"/„landing page"/„index" außer der Erwähnung eines
„Home"-Navigationseintrags als gewöhnlicher Site-Abschnitt. Die Konfigurationsseite sagt
ausdrücklich nur: „Zensical understands your existing `mkdocs.yml` configuration and
automatically adapts it for use within Zensical's own format." — Zensical übernimmt also die
MkDocs-Konvention (`docs/index.md` als Startseite über die `nav`-Liste), ohne eine eigene
Startseiten-Vorlage, ein Startseiten-Layout oder eine gesonderte Konfigurationsangabe
einzuführen.

Ein direkter Abruf von `https://zensical.org/docs/` selbst lieferte nur die JS-Hülle (leere
Seite mit Titel „Zensical Documentation") — kein Fehler, aber ohne Aussagekraft; die
inhaltliche Prüfung lief deshalb über die beiden oben genannten Unterseiten von
`zensical.org`, die Teil derselben Primärdokumentation sind.

**Damit ist die im Konzept unter Vorbehalt stehende Annahme bestätigt** — die bereits
vorhandene `docs/index.md` mit YAML-Frontmatter (`title: Home`, `icon: lucide/rocket`) plus
Eintrag in `zensical.toml` unter `nav = [{ "Home" = "index.md" }, ...]` ist korrekt und
braucht keine Nachbesserung.

## Bau — wirklich ausgeführt, nicht nur die Konfiguration gelesen

`zensical` war im System nicht installiert (`pip install` schlug mit
„externally-managed-environment" fehl, PEP 668). Gelöst über ein eigenes, isoliertes
Scratch-venv (`python3 -m venv .venv-docs-build`, `pip install zensical==0.0.54`) statt den
Schritt zu überspringen.

```
$ .venv-docs-build/bin/zensical build --clean
Build started
No issues found
Build finished in 1.23s
```

Ergebnis geprüft, nicht nur den Rückgabewert genommen:

- `site/index.html` trägt `<title>Home - Tesserarr</title>` — Frontmatter wurde korrekt
  ausgewertet.
- Navigation im generierten HTML zeigt `./setup/` und `./roadmap/` — beide Seiten sind
  erreichbar.
- `site/adr/000{1..9}-*/` wurden ebenfalls gerendert (die neun ADRs liegen unter `docs/adr/`
  und werden von Zensical automatisch mitgebaut, auch wenn sie **nicht** in der `nav`-Liste
  stehen — Zugriff auf sie läuft bewusst über die GitHub-Links in `index.md`/`setup.md`, nicht
  über die Seitennavigation, das ist so im Bestand angelegt und passt zum verlangten
  Roadmap-Gerüst-Umfang).

## Zwei Grenzen aus dem Auftrag — geprüft

**Keine Interna:** `grep` über `docs/`, `zensical.toml`, `README.md` nach privaten
IP-Bereichen, internen Hostnamen (`hhdocker*`, `hhplex*`) und den drei internen Domains
(`strausmann.cloud/.net/.de`) fand nichts — alle Treffer für „homelab"/„Pangolin" sind
generische Konzeptbegriffe bzw. der Name eines echten Open-Source-Reverse-Proxys als
Deploy-Variante (`compose.pangolin.yaml`), kein interner Wert.

**Prosa auf Englisch:** `docs/index.md`, `docs/setup.md`, `docs/roadmap.md` sind bereits
durchgängig Englisch. Die ADR-Dateien unter `docs/adr/` sind — wie `docs/adr/README.md` selbst
festlegt — bewusst Deutsch (Prosa-Sprache der ADRs, Code/CLI englisch); das ist eine
Repo-eigene, im ADR-Regelwerk dokumentierte Entscheidung und keine Abweichung vom
Englisch-Gebot für die eigentliche Website-Prosa.

## Was ich zusätzlich getan habe

- `.gitignore` um `/.venv-docs-build/` ergänzt (Commit `5e3b20a`), damit das Scratch-venv nie
  versehentlich eingecheckt wird.
- `pnpm install` ausgeführt, bevor committet wurde — `git config core.hooksPath` war im Klon
  zunächst leer, danach `.husky/_` (siehe `eigener-arbeitsbereich-je-bearbeiter.md`,
  Nebenwirkung „Im frischen Klon schweigen die Commit-Prüfungen"). Der Commit lief damit durch
  den commitlint-Hook, nicht ungeprüft.
- Scratch-venv nach dem Bau wieder entfernt (`rm -rf .venv-docs-build`) — in Sekunden
  neu erzeugbar, gehört nicht in den Arbeitsbereich.

## Nicht angefasst

Kein Go-Code, keine `internal/`-Pakete — wie im Auftrag verlangt. Kein Push, keine
Zusammenführung — beides bleibt beim Team-Lead.

## Status

Fertig. Die Doku-Website existiert, baut sauber, die einzige offene Prüffrage aus dem Konzept
ist mit Primärquelle beantwortet (kein zusätzlicher Zensical-Startseiten-Mechanismus).
