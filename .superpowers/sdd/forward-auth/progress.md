# SDD ledger — plan: docs/superpowers/plans/2026-08-14-tesserarr-forward-auth.md

Integrationszweig: `feat/0.0.2-forward-auth` in `/opt/repos/tesserarr-0-0-1-teil-a`,
abgezweigt von `feat/0.0.1-geruest` (Antrag #1, noch nicht zusammengefuehrt).

**Gepusht wird von Anfang an.** Der entfernte Pruflauf ist Teil von „fertig" — ein lokaler Lauf
zaehlt nicht. Das war der teuerste Fehler der Nacht vom 13./14.08.

## Aufgaben

| # | Was | Stand |
|---|---|---|
| 1 | Konfiguration: Schalter, Voreinstellungen, Rollen-Zuordnung | offen |
| 2 | Vertrauenspruefung: Quell-IP und gemeinsames Geheimnis | offen |
| 3 | Rollen-Aufloesung (reine Logik) | offen |
| 4 | Migration + `identity.ForwardAuthLogin` | offen |
| 5 | HTTP-Eintrittspfad | offen |
| 6 | API-Token: Ablauf und Widerruf | offen |
| 7 | Token-Verwaltung: HTTP-Endpunkte | offen |
| 8 | Kontoansicht Rueckgrat | offen |
| 9 | Kontoansicht Oberflaeche | offen |
| 10 | Kommandozeile: `user remap`, `user promote` | offen |
| 11 | Einrichtungs-Doku je Portal | offen |
| 12 | ADR-0010 | offen |
| 13 | Abschlusspruefung | offen |

## Regeln fuer diesen Lauf

- Ein Bearbeiter, ein eigener Klon (`/opt/repos/tesserarr-fa-<n>`), eigener `GOLANGCI_LINT_CACHE`
- Jeder Test einmal gegen die Fassung **ohne** den jeweiligen Schutz stellen; bleibt er gruen,
  ist das ein Ergebnis und wird gemeldet
- Nicht selbst zusammenfuehren — die Freigabe bleibt beim Auftraggeber
