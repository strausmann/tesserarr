# Architecture Decision Records — Tesserarr

Diese Registry führt alle Architecture Decision Records (ADRs) für Tesserarr. ADRs dokumentieren
wichtige, langfristig wirkende Entscheidungen — inklusive Kontext und Konsequenzen — damit
spätere Sessions und Mitwirkende nachvollziehen können, **warum** etwas so gebaut wurde und nicht
anders.

Diese Dateien liegen als Zwischenlager im Verwaltungsrepo (`homelab-management`) unter
`docs/superpowers/specs/tesserarr-adr/`, solange das eigentliche Tesserarr-Repository noch nicht
existiert. Sobald es angelegt ist, wandern sie unverändert nach `docs/adr/` dieses neuen
Repositorys — Verweise untereinander sind deshalb bewusst **relativ** gehalten, damit sie den
Umzug überstehen.

Neues ADR anlegen: ein bestehendes ADR aus diesem Verzeichnis als Vorlage kopieren (Felder
Status/Datum/Lineage/Kontext/Entscheidung/Konsequenzen/Referenzen) nach `NNNN-slug.md` (nächste
freie Nummer, vierstellig) und unten in die Tabelle eintragen.

## Nummernkreis

Tesserarr ist eine neue Projektfamilie, kein Anhängsel eines bestehenden Nummernkreises. Die
Zählung beginnt bei **0001**.

## ADR-Regelwerk

- **Was/Wann:** Ein ADR dokumentiert jede bedeutsame Architektur-, Technologie- oder
  Betriebs-Entscheidung samt Kontext und Konsequenzen. **Nicht** für Trivialitäten, reine
  Formatierung oder offensichtliche Umsetzungsdetails.
- **Nummerierung:** fortlaufend `NNNN` (4-stellig), Dateiname `NNNN-kebab-slug.md`. Nummern
  werden **nie wiederverwendet** — auch nicht für abgelöste oder verworfene ADRs.
- **Status-Lifecycle:** `proposed` → `accepted` → (`superseded` | `deprecated`).
- **Lineage (beidseitig pflegen):** Die Header-Felder `Ersetzt` / `Ersetzt durch` (vollständige
  Ablösung → Vorgänger auf `superseded` setzen), `Überarbeitet` / `Überarbeitet durch`
  (Teiländerung → Vorgänger bleibt `accepted`) und `Verwandt` (Querbezug ohne Ablösung) werden auf
  **beiden** beteiligten ADRs eingetragen. Beim Ablösen wird nur der **Header** des alten ADR
  angefasst — Kontext und Entscheidung des alten ADR werden nie umgeschrieben, sie sind ein
  historisches Protokoll.
- **Registry-Pflicht:** Jedes neue oder im Status geänderte ADR wird sofort in die Tabelle unten
  eingetragen bzw. aktualisiert. Ein ADR, das nicht in der Registry steht, gilt als übersehen und
  damit als nicht existent.
- **Sprache:** Deutsch (echte Umlaute ä ö ü ß), Code/CLI/Bezeichner auf Englisch.
- **Vertraulichkeit:** Tesserarr ist ein öffentliches Projekt. Kein ADR nennt Namen, Adressen,
  Rechnernamen, Umgebungsangaben oder sonstige Interna — auch nicht in Beispielen. Dort gelten
  Platzhalter.

## Registry

| Nr. | Titel | Status | Datum |
|-----|-------|--------|-------|
| [0001](0001-anbieter-seitiger-einzeldienst.md) | Anbieter-seitiger Einzeldienst — der Empfänger installiert nichts | accepted | 2026-08-13 |
| [0002](0002-usenet-nachbildung-statt-torrent.md) | Usenet-Nachbildung statt Torrent | accepted | 2026-08-13 |
| [0003](0003-hardlink-bevorzugt-kopie-als-rueckfall.md) | Hardlink bevorzugt, Kopie als Rückfall | accepted | 2026-08-13 |
| [0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md) | Einladung zwingend, Anmeldedienst liefert nur die Identität | accepted | 2026-08-13 |
| [0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md) | Go-Kern, getrennte Oberfläche, ein gemeinsames Abbild | accepted | 2026-08-13 |
| [0006](0006-oberflaechenpruefung-als-hartes-tor.md) | Oberflächenprüfung als hartes Tor | accepted | 2026-08-13 |
| [0007](0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md) | Anmeldung — Sitzung für die Oberfläche, Token für Fremdsysteme, getrennte Pfade | accepted | 2026-08-13 |
| [0008](0008-widerruf-wirkt-auf-beide-wege-kopplung-am-nutzer.md) | Widerruf wirkt auf beide Wege — Kopplung am Nutzer | accepted | 2026-08-13 |
| [0009](0009-zwei-getrennte-protokollstroeme.md) | Zwei getrennte Protokollströme | accepted | 2026-08-13 |

## Status-Werte

| Status | Bedeutung |
|--------|-----------|
| `proposed` | Entwurf, noch nicht final entschieden |
| `accepted` | Gültig, wird umgesetzt/befolgt |
| `superseded` | Durch ein neueres ADR vollständig abgelöst (siehe `Ersetzt durch` im Header) |
| `deprecated` | Nicht mehr gültig, ohne direkten Nachfolger |
