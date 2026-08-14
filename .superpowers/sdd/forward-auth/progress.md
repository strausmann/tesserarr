# SDD ledger — plan: docs/superpowers/plans/2026-08-14-tesserarr-forward-auth.md

Integrationszweig: `feat/0.0.2-forward-auth` in `/opt/repos/tesserarr-0-0-1-teil-a`,
abgezweigt von `feat/0.0.1-geruest` (Antrag #1, noch nicht zusammengefuehrt).

**Gepusht wird von Anfang an.** Der entfernte Pruflauf ist Teil von „fertig" — ein lokaler Lauf
zaehlt nicht. Das war der teuerste Fehler der Nacht vom 13./14.08.

## Aufgaben

| # | Was | Stand |
|---|---|---|
| 1 | Konfiguration: Schalter, Voreinstellungen, Rollen-Zuordnung | **fertig**, zusammengefuehrt |
| 2 | Vertrauenspruefung: Quell-IP und gemeinsames Geheimnis | **fertig**, zusammengefuehrt |
| 3 | Rollen-Aufloesung (reine Logik) | laeuft (`fa-3-roles`) |
| 4 | Migration + `identity.ForwardAuthLogin` | offen |
| 5 | HTTP-Eintrittspfad | offen |
| 6 | API-Token: Ablauf und Widerruf | offen |
| 7 | Token-Verwaltung: HTTP-Endpunkte | offen |
| 8 | Kontoansicht Rueckgrat | offen |
| 9 | Kontoansicht Oberflaeche | offen |
| 10 | Kommandozeile: `user remap`, `user promote` | offen |
| 11 | Einrichtungs-Doku je Portal | offen — **plus Hinweis zur Log-Schwaerzung**, siehe unten |
| 12 | ADR-0010 | **fertig**, zusammengefuehrt |
| 13 | Abschlusspruefung | offen |

## Regeln fuer diesen Lauf

- Ein Bearbeiter, ein eigener Klon (`/opt/repos/tesserarr-fa-<n>`), eigener `GOLANGCI_LINT_CACHE`
- Jeder Test einmal gegen die Fassung **ohne** den jeweiligen Schutz stellen; bleibt er gruen,
  ist das ein Ergebnis und wird gemeldet
- Nicht selbst zusammenfuehren — die Freigabe bleibt beim Auftraggeber

## Befunde, die nicht verlorengehen duerfen

- **Wanderungsschritte duerfen nicht ausser der Reihe landen.** `store.go` verweigert eine
  Wanderung, deren Nummer niedriger ist als die hoechste bereits angewandte. Aufgabe 6 (0005) darf
  deshalb **nicht** vor Aufgabe 4 (0004) zusammengefuehrt werden -- sonst wird 0004 auf jeder
  laufenden Instanz abgewiesen. **Keine Parallelisierung ueber Wanderungsschritte hinweg.**
- **Ein `bool`-Feld auf `Config` bricht `go vet` bei `%q` auf dem Struct** -- vet prueft rekursiv,
  ob jedes Feld quotierbar ist. Nicht mit `//nolint` zu erschlagen, weil CI `go vet` als eigenen
  harten Schritt faehrt. Die `%q`-Faelle auf dem Struct sind entfallen; `%q` auf dem
  Geheimnis-Typ selbst bleibt in `secret_test.go` geprueft.
- **Die Commit-Nachrichten im Plan waren deutsch**, das Repo ist englisch. 17 im Plan korrigiert,
  die drei von Aufgabe 1 bewusst stehengelassen (die zugehoerigen Commits existieren bereits).
- **Der Commit-Haken haengt am Wurzel-`package.json`**, nicht an `web/`. Wer nur dort installiert,
  committet ungeprueft.
- **Einladungs-Kennzeichen im Anfrageparameter** (`?invite=`) landet in Zugriffsprotokollen und
  Referrer-Kopfzeilen. **Kein neuer Kanal** -- der bestehende Pfad `/invite/{token}` steht als Teil
  derselben Adresse genauso darin, und das Kennzeichen ist einmalig, ein geleakter Wert nach der
  Einloesung also wertlos. **Aufgabe 11 nimmt einen Hinweis auf**, wie man Einladungslinks im
  Zugriffsprotokoll schwaerzt -- damit es niemand spaeter als Fund meldet, der bereits abgewogen
  wurde.
