# SDD ledger — plan: docs/superpowers/plans/2026-08-13-tesserarr-0-0-1-teil-b-zugang.md

Nachgetragen am 14.08.2026, **nachdem** das Fehlen dieses Buchs bereits Schaden angerichtet hat:
Aufgabe 9 war seit `7a6957c` erledigt, wurde aber ein zweites Mal beauftragt, weil der Verlauf
nicht gelesen wurde. Der Bearbeiter wurde abgebrochen, bevor er committen konnte. Genau davor
warnt das Verfahren — das Buch schlägt die Erinnerung, und ohne Buch bleibt nur die Erinnerung.

Alle Kennungen beziehen sich auf den Integrationszweig `feat/0.0.1-geruest` im Klon
`/opt/repos/tesserarr-0-0-1-teil-a`.

## Erledigt

| Aufgabe | Zusammenführung | Anmerkung |
|---|---|---|
| 1 — `Secret` am Feld | `5b76935`, `2600900` | Redaktion am Wert, nicht am Struct |
| 2 — Sitzungen | `fe1d057` | erzeugen, prüfen, rotieren |
| 3 — Mittelschichten | `df0b7b9` | Trennung zum ersten Mal scharf geprüft |
| 4 — Fälschungsschutz | `afe7da2` | Double-Submit, nur Sitzungspfad |
| 5 — OIDC-Anmeldung | `a36eb46` | **nur der Baustein** — siehe Offen |
| 6 — Einladungen | `af899e9` | nebenläufige Einlösung getestet |
| 9 — Doku-Website | `7a6957c` | Zensical, Startseite, Einrichtung, Roadmap, Arbeitsablauf |
| zusätzlich — API-Token-Speicher | `9b257f2` | die zweite, unabhängige Schranke |
| zusätzlich — `MiddlewarePublic` | `f12c297`, `14dbca4` | ausdrückliche Liste; `MiddlewareNone` abgeschafft |
| zusätzlich — Erstinbetriebnahme | `11a727c` | Weg zur allerersten Einladung |
| **Anmeldung an HTTP angebunden** (Lücke aus Aufgabe 5) | zwei Zusammenführungen von `rlogin/b-login` | vier Endpunkte; man kann sich jetzt tatsächlich anmelden |
| zusätzlich — Doku-Prüfung Zensical | `d4415a6` | Startseiten-Mechanismus vierfach belegt widerlegt |
| zusätzlich — Wissensspeicher-Übertrag | Bericht `64460a3` im Verwaltungsrepo | 15 Dokumente geprüft, 2 geschrieben |

## Läuft gerade

| Was | Bearbeiter | Arbeitsbereich |
|---|---|---|
| Aufgabe 7, Schritte 1–2 (Oberfläche + Einbetten + Vitest) | `impl-ui` | `/opt/repos/tesserarr-b-ui`, Zweig `b-ui` |

## Beim Zusammenführen von `b-ui` beachten

`router.go` wurde seit dessen Abzweig **zweimal** verändert (Abschaffung `MiddlewareNone`, vier
Anmelde-Registrierungen). `b-ui` fasst die Datei auftragsgemäss **nicht** an, der Konflikt ist also
unwahrscheinlich — aber die anschliessenden Schritte 3–4 (Ausliefern der eingebetteten Dateien)
gehen genau dort hinein. Erst zusammenführen, dann verdrahten.

## Offen

- **Aufgabe 7, Schritte 3–4** — Ausliefern der eingebetteten Dateien aus dem Go-Kern und
  Handprobe. Bewusst zurückgestellt: beide laufenden Bearbeiter würden sonst in `router.go`
  kollidieren.
- **Aufgabe 8** — Oberflächenprüfung mit Playwright, dazu der neue Skill `ui-verification`.
  Setzt Aufgabe 7 voraus.
- **Abschluss von Teil B** — Sicherheits-Audit über die geänderte Fläche, externe Prüf-Bots vor
  dem Zusammenführen, Abdeckung `identity` und `invite` ≥ 90 % nachweisen.

## Befunde, die nicht verlorengehen dürfen

- **Ein Test kann durch eine Zusammenführung falsch werden, ohne dass jemand ihn anfasst.** Der
  Fälschungsschutz war gegen eine Welt getestet, in der ein Sitzungs-Kennzeichen noch als
  Bearer-Token durchging; nach dem Zusammenlegen mit dem getrennten Token-Speicher kam korrekt
  eine 401. Der Test lag falsch, nicht der Code (`7e76534`). **Er lief in beiden Klonen einzeln
  grün** — nur zusammen nicht.
- **Der Zwischenspeicher von `golangci-lint` ist über alle Klone derselbe.** Befunde aus fremden
  Verzeichnissen und „parallel golangci-lint is running" sind die Folge. Eigenes Verzeichnis je
  Bearbeiter setzen, den geteilten nie leeren. Als Regel festgehalten.
- **Berichte der Bearbeiter liegen in deren Klonen**, nicht hier — sie erscheinen unter
  `.superpowers/sdd/teil-b/` erst mit dem Zusammenführen ihres Zweigs.
