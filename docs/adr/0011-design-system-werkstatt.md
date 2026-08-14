# ADR-0011: Design-System „Werkstatt" für Tesserarr

**Status:** accepted
**Datum:** 2026-08-14
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0006](0006-oberflaechenpruefung-als-hartes-tor.md)

## Kontext

Tesserarrs Weboberfläche bestand bislang aus wenigen Seiten (Startseite, Anmeldung, Abmeldung)
mit einem eigenen, aber unentschiedenen Stil (`web/src/app/globals.css`, rund 130 Zeilen): ein
Farbsatz für beide Modi, eine vierstufige Abstandsskala, `.page`/`.card`/`.stack`/`.button`. Die
Zugänglichkeits-Zusagen aus [ADR-0006](0006-oberflaechenpruefung-als-hartes-tor.md) — sichtbarer
Tastaturfokus über `:focus-visible`, kein Querscrollen, `axe`-Prüfung in der
Playwright-Oberflächenprüfung — waren erfüllt, aber ohne ein System, das sie beim nächsten
Bauteil automatisch mitträgt. Jede neue Ansicht hätte erneut entschieden, statt aus einem
gemeinsamen Vokabular zu bauen.

Erster Nutzer, der bewusst auf dieses System wartet, statt es nachträglich nachzuziehen: die
Kontoansicht aus Aufgabe 9 des Forward-Auth-Plans.

Als Anschauung diente Dockhand (`/opt/repos/dockhand`), am Quellcode geprüft: sein
Gestaltungssystem ist im Kern das öffentliche shadcn/ui-Token-Vokabular auf Tailwind 4
(`background/foreground/card/popover/primary/secondary/muted/accent/destructive/border/input/ring`
plus `--radius`), mit einer Themen-Maschine aus 27 Farbwelten und 38 Schriften, unter der
Business Source License 1.1. Tesserarr steht unter MIT — fremden Quellcode zu übernehmen hiesse,
ihn umzulizenzieren, was die Nutzungserlaubnis der BUSL nicht deckt. Übernommen wird deshalb
ausschliesslich die **Struktur** des Token-Vokabulars (öffentliche, weit verbreitete Konvention)
sowie die Handwerklichkeit einer dichten Betriebs-Oberfläche — nicht Werte, CSS oder
Themen-Maschine. Alle Farbwerte des neuen Systems sind eigene.

Zur Wahl standen mehrere Gestaltungsrichtungen (Vergleichsseite siehe Referenzen). Entschieden
wurde für **„Werkstatt"**: dicht, kühl, nüchtern — ein Werkzeug, kein Produkt. Das ist zugleich
die Richtung, die der Referenz am nächsten liegt; die Unterscheidung zu Dockhand war deshalb
ausdrückliche Gestaltungsaufgabe, kein Nebeneffekt. Drei Entscheidungen tragen sie: Haarlinien
statt Karten als Trennmittel, entsättigter Stahl (~25 % Sättigung) statt Signalblau (Dockhand
~83 %), und nahezu quadratische Ecken (3 px Radius statt 8 px).

## Entscheidung

Tesserarr bekommt ein benanntes, verbindliches Design-System, „Werkstatt", mit eigenem
Token-Satz (`--color-*`, `--space-*`, Typografie) in `web/src/app/globals.css`. Alle künftigen
Oberflächen werden aus diesem Vokabular gebaut — keine Komponente bekommt eigene Farbwerte.

Vier Regeln binden ab jetzt jeden Baustein, weil ihre Verletzung entweder bereits als konkreter
Mangel gefunden wurde oder ohne die zugrundeliegende Kontrastrechnung nicht offensichtlich ist:

1. **Der Akzent ist Handlungen vorbehalten.** `--color-accent` erscheint ausschliesslich bei
   Handlungen (Speichern, Token erzeugen). Zustand — läuft, Achtung, Fehler — spricht nie in
   Akzentfarbe, und eine Handlung nie in einer der Zustandsfarben `--color-ok`/`--color-warn`/
   `--color-danger`. Einzige Ausnahme: die gefährliche Handlung steht bewusst in
   `--color-danger` — sie ist zugleich Handlung und Warnung vor ihrer eigenen Wirkung.
2. **Eingabefelder verwenden `--color-input-border`, nie `--color-border`.** Gegen die
   Eingabefläche (`#ffffff` hell, `#131c2a` Navy) erreicht `--color-border` nur 1.34 (hell) bzw.
   1.30 (Navy) — weit unter den für Bedienelemente geforderten 3:1 (WCAG 1.4.11). Der eigens
   dafür angelegte Token `--color-input-border` erreicht 4.05 bzw. 3.67 (hell, gegen Fläche und
   Hintergrund) und 3.41 bzw. 3.14 (Navy). Der Rahmen ist bei einem Feld das Erkennungsmerkmal
   selbst — nicht Schmuck wie bei einer Trennlinie zwischen Listenzeilen —, deshalb gilt hier
   keine Ausnahme von der 3:1-Schwelle.
3. **`outline-offset` bleibt über 0.** Der Fokusring erreicht gegen die Akzentfläche, auf der er
   am häufigsten sichtbar wird, nur 1.39 (hell) bzw. 1.52 (Navy) — weit unter 3:1, und kein
   anderer Farbton würde das beheben, nur ein Helligkeitsunterschied. Er besteht die
   Kontrastprüfung ausschliesslich, weil `outline-offset: 2px` ihn nach aussen auf den
   umgebenden Grund setzt: dort erreicht er 4.70–5.18 (hell, gegen Hintergrund bzw. Fläche) und
   9.76–10.61 (Navy). Der Offset ist damit keine Geschmacksfrage, sondern die Bedingung, unter
   der der Ring überhaupt besteht, und darf nicht auf 0 gesetzt werden.
4. **Genau eine Karte im ganzen System.** `.callout` ist reserviert für den einmalig sichtbaren
   Klartext-Token (Fläche `--color-surface`, 1 px Rahmen, 3 px Akzentkante links). Jede andere
   Trennung läuft über Haarlinien (`--color-hairline`) und Weissraum, nicht über Rahmen oder
   Schatten — das ist der stärkste Unterschied zum kartenbasierten shadcn-Vorbild und kostet
   nichts.

Diese vier Regeln stehen neben dem übrigen, im Entwurf festgehaltenen Vokabular (Abstandsskala,
Typografie, zehn Komponenten-Bausteine, Schriftwahl) — sie sind die Teilmenge, die hier
ausdrücklich verbindlich festgeschrieben wird, weil sie sonst leicht unbemerkt gebrochen werden
kann.

## Konsequenzen

**Positiv**

- Neue Ansichten — beginnend mit der Kontoansicht aus Aufgabe 9 — entstehen aus einem festen
  Vokabular statt aus Einzelentscheidungen: schneller, konsistent, ohne erneute Kontrastrechnung
  pro Komponente.
- Die Kontrast-Nachweise für Text, Eingabefeld und Fokusring sind bereits erbracht, nicht nur
  behauptet (nachgerechnet gegen WCAG 2.1: 4.5:1 für Text, 3:1 für Bedienelemente). Die
  `axe`-Prüfung aus ADR-0006 bleibt die laufende Absicherung gegen Rückfall, nicht der
  Erstnachweis.
- Der Unterschied zur Dockhand-Referenz ist bewusst getroffen und dokumentiert, nicht zufällig
  entstanden — das verhindert, dass künftige Bauteile sich unbemerkt an die Referenz annähern.

**Negativ**

- Vier zusätzliche verbindliche Regeln, gegen die jede künftige Komponente geprüft werden muss.
  Ein Verstoss (Akzent für Zustand, `--color-border` an einem Feld, `outline-offset: 0`, eine
  zweite Karte) wird von der bestehenden Playwright-Oberflächenprüfung nur über den
  `axe`-Kontrast-Check indirekt erfasst — eine gezielte Sichtprüfung im Review bleibt nötig, ein
  automatisches Tor für genau diese vier Regeln existiert nicht.
- Das System gilt ausschliesslich für Tesserarr. Keine Wiederverwendung für Hangar oder
  Print-Hub ohne eigene, separate Entscheidung.
- Die Beschränkung auf genau eine Karte kann bei künftigem Bedarf — etwa dem im Entwurf
  benannten, bewusst zurückgestellten Kachel-Motiv — eine erneute Abwägung verlangen, sobald ein
  Baustein eine Fläche tatsächlich braucht.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn ein künftiger Baustein eine der vier Regeln nicht ohne Bruch erfüllen kann — etwa ein
  zweiter, tatsächlich nötiger Anwendungsfall für eine Karte —, wird das hier nachgetragen,
  nicht die Regel stillschweigend gebrochen.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-14-tesserarr-design-system-design.md`, Abschnitte „Standpunkt: Instrument, nicht Dashboard", „Die Regel dahinter" und „Nachweis statt Behauptung — bereits erbracht" |
| Forward-Auth-Plan (Aufgabe 9 als erster Nutzer) | `docs/superpowers/plans/2026-08-14-tesserarr-forward-auth.md` |
| Bewertung des Gestaltungswerkzeugs | `docs/research/2026-08-12-impeccable-style-evaluation.md` |
| Vergleichsseite der drei Richtungen | https://claude.ai/code/artifact/f96c9b4b-fd37-4352-b475-bba77f929d2d |
