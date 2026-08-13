# ADR-0008: Widerruf wirkt auf beide Wege — Kopplung am Nutzer

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0001](0001-anbieter-seitiger-einzeldienst.md), [ADR-0003](0003-hardlink-bevorzugt-kopie-als-rueckfall.md), [ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md)

## Kontext

Eine adversariale Prüfung des Entwurfs (`docs/research/2026-08-13-tesserarr-bedrohungsanalyse.md`,
Befund 1) deckte auf: Zugang, Freigaben und Kontingente steuern ausschliesslich den **Anfrageweg**
über Tesserarr. Die eigentliche Nutzlast läuft über eine Syncthing-Ordnerfreigabe zwischen
Anbieter und Empfänger, die laut ursprünglichem Entwurf **von Hand** eingerichtet wird — und die
diese Kopplung überdauert einen Widerruf, weil kein Baustein (`request`, `identity`, `store`) je
eine Rückwirkung von `Nutzer.aktiv = false` auf die Syncthing-Konfiguration beschrieb.

**Einordnung der Schwere — Korrektur gegenüber der ursprünglichen Bedrohungsanalyse.** Der Befund
sprach zunächst von „dauerhaftem, unbemerktem Zugriff" auf die Bibliothek. Das trifft nicht zu,
aus zwei bereits an anderer Stelle im Entwurf festgelegten Gründen: Der Ordner fliesst nur in
**eine** Richtung — der Empfänger kann ausschliesslich holen, was der Anbieter zuvor
hineingelegt hat, nie selbst etwas hinzufügen (siehe [ADR-0001](0001-anbieter-seitiger-einzeldienst.md)
und [ADR-0003](0003-hardlink-bevorzugt-kopie-als-rueckfall.md)). Und nach einem Widerruf legt
niemand mehr etwas Neues in diesen Ordner, weil der Anfrageweg sofort schliesst — was zu diesem
Zeitpunkt bereits dort lag, räumt die Karenzzeit ohnehin weg. **Die tatsächliche Aussetzung ist
deshalb der aktuelle Inhalt des Ausgangsordners, längstens bis zum Ablauf der Karenzzeit — nicht
die Bibliothek, und nicht dauerhaft.** Diese Korrektur gehört mit ins Protokoll: Ein ADR, das eine
überzeichnete Schwere unkommentiert übernimmt, treibt später falsche Priorisierung.

Was **trotzdem** bleibt, und das ist der eigentliche Kern des Befunds: Die Geräte-Kopplung selbst
überdauert den Widerruf, solange sie nicht aktiv abgebaut wird — der Rechner des ehemaligen
Empfängers bleibt für die eigene Syncthing-Instanz ein bekanntes, vertrauenswürdiges Gerät. Und
der Widerruf meldet „erledigt", obwohl ein Teil des Weges technisch noch offen sein kann.

**Verworfene Varianten:**

1. **Ein reiner Ein/Aus-Schalter** (eine `_ENABLED=true/false`-Variable ohne tatsächlich
   funktionierenden Zugang dahinter), um zwischen automatischer und manueller Betriebsart zu
   wechseln. Verworfen: Ein Schalter könnte dem wahren Zustand widersprechen — in der
   Konfiguration stünde „automatisch", während beim Widerruf tatsächlich nichts geschieht, weil
   der hinterlegte Zugang gar nicht funktioniert.
2. **Kopplung an der Anmelde-Identität statt am Nutzer.** So sah es das Datenmodell des Entwurfs
   ursprünglich vor (`Identität.syncthing_geraet_id`/`syncthing_ordner_id`). Verworfen: Nutzer und
   Anmelde-Identität stehen im Verhältnis 1:n
   ([ADR-0004](0004-einladung-zwingend-anmeldedienst-liefert-nur-identitaet.md)) — hinge die
   Kopplung an der Identität, entstünde bei einem zweiten Anmeldeweg desselben Menschen eine
   zweite, unabhängige Kopplung für dasselbe physische Gerät, und der Widerruf müsste über alle
   Identitäten iterieren, um vollständig zu sein. Genau dieselbe Überlegung hat in ADR-0004 schon
   einmal dazu geführt, Nutzer und Identität überhaupt zu trennen.
3. **Der Fehlerfall gilt trotzdem als erledigt** — ein optimistisches „Widerruf gemeldet heisst
   Widerruf geschehen", um dem Betreiber sofortige Gewissheit zu geben. Verworfen: Eine Zusage,
   die nicht vollständig zutrifft, ist schlimmer als eine, die ihre Grenze klar benennt.

## Entscheidung

1. Es gibt **zwei Betriebsarten**. Welche gilt, ergibt sich aus dem **hinterlegten Zugang**, nicht
   aus einem Schalter — ein Schalter könnte dem tatsächlichen Zustand widersprechen.
2. **Mit Schreibzugang** (`TESSERARR_SYNCTHING_MANAGE_API_KEY`, getrennt vom ohnehin
   erforderlichen Lese-Schlüssel) verwaltet Tesserarr die Kopplung selbst: Ordner anlegen, teilen,
   beim Widerruf aufheben.
3. **Ohne Schreibzugang** gilt der geführte, zweistufige Weg: Der Betreiber richtet die
   Ordnerfreigabe von Hand ein und hebt sie von Hand wieder auf, mit sichtbarem Zustand
   „Widerruf unvollständig", bis der Betreiber die Aufhebung bestätigt.
4. **Der Fehlerfall schliesst.** Scheitert das automatische Aufheben (Syncthing nicht erreichbar,
   Rechte fehlen, Ordner-ID passt nicht mehr), gilt der Widerruf **nicht** als erledigt — derselbe
   unvollständige Zustand wie im manuellen Weg, mit eigenem Protokolleintrag.
5. **In beiden Betriebsarten greift der Anfrageweg sofort und ohne Bedingung.** Der widerrufene
   Nutzer kann sich nicht mehr anmelden, kein Token mehr nutzen, keine neue Anfrage stellen —
   unabhängig davon, ob die Syncthing-Kopplung automatisch oder manuell abgebaut wird.
6. **Die Kopplung hängt am Nutzer, nicht an der Anmelde-Identität.** Sie beschreibt den Rechner
   eines Menschen, nicht die Art, wie er sich anmeldet. An der Identität hängend entstünde bei
   einem zweiten Anmeldeweg eine zweite Kopplung für dasselbe Gerät — und der Widerruf müsste
   iterieren, um vollständig zu sein.
7. **Mehrere Geräte je Freund sind nicht vorgesehen.** Falls das je nötig wird, ist das eine
   eigene Beziehung zwischen Nutzer und Geräten — kein Anhängsel an die Identitäten.

## Konsequenzen

**Positiv**

- Die Schwere ist jetzt korrekt eingeordnet: begrenzt auf den aktuellen Ordnerinhalt bis zur
  Karenzzeit, nicht auf eine dauerhaft offene Bibliothek — die Grundlage für jede spätere
  Priorisierung stimmt.
- Der Anfrageweg schliesst **sofort und bedingungslos** in beiden Betriebsarten — die für den
  Betreiber wichtigste Zusicherung ist robust, unabhängig vom tatsächlichen Zustand der
  Syncthing-Kopplung.
- Die Betriebsart ergibt sich aus dem tatsächlichen, geprüften Zugang, nicht aus einem Schalter,
  der der Konfiguration widersprechen könnte.
- Der Fehlerfall schliesst statt zu öffnen: Eine unvollständige Zusage bleibt sichtbar
  („Widerruf unvollständig"), statt fälschlich als erledigt zu gelten.
- Die Kopplung am Nutzer statt an der Identität vermeidet eine zweite, unbemerkte Kopplung bei
  einem zweiten Anmeldeweg — konsistent mit der Begründung, die Nutzer und Identität in
  ADR-0004 überhaupt erst getrennt hat.

**Negativ**

- **Der Schreibzugang macht Tesserarr selbst zu einem Ziel gegen die Syncthing-Konfiguration des
  Betreibers.** Und — das ist der ehrliche Teil — **Syncthings Schnittstelle kennt selbst keine
  Begrenzung je Schlüssel**; ein Schlüssel gilt technisch immer für die gesamte Instanz, nie nur
  für einzelne Ordner. Die enge Fassung („nur die eigenen Ordner, niemals globale Einstellungen")
  ist damit **Tesserarrs eigene Disziplin, keine Systemgrenze**. Wer das nicht weiss, hält eine
  Selbstverpflichtung für eine technische Schranke — ein Implementierungsfehler in genau diesem
  Baustein hätte Zugriff auf die gesamte Syncthing-Instanz des Anbieters, nicht nur auf die von
  Tesserarr verwalteten Ordner.
- **Spannung zum 1:n-Identitätsmodell aus ADR-0004.** Jenes ADR erlaubt ausdrücklich mehrere
  Anmeldewege für denselben Nutzer. Dieses ADR bindet dagegen genau **eine** Geräte-Kopplung an
  den Nutzer und schliesst mehrere Geräte je Freund ausdrücklich aus (Entscheidungspunkt 7).
  Meldet sich derselbe Mensch über zwei Anmeldewege von zwei unterschiedlichen physischen Geräten
  an — etwa Laptop über den OIDC-Anbieter, Mobiltelefon über Plex —, hat dieses Modell dafür
  keinen Platz: Entweder teilen sich beide Anmeldewege dieselbe einzige Kopplung (was für eines
  der beiden Geräte nicht zutrifft), oder der zweite Anmeldeweg bleibt ohne Transportweg (siehe
  „Zugang zum Dienst ist nicht Zugang zum Transport" im Konzept). Das ist keine Inkonsistenz
  zwischen den beiden ADRs, aber eine Grenze, die bei der Umsetzung leicht übersehen werden kann,
  weil beide Entscheidungen für sich genommen folgerichtig klingen.
- **Der geführte (manuelle) Weg hat keinen automatischen Wiederholungsversuch.** Im verwalteten
  Weg endet der Zustand „Widerruf unvollständig", sobald ein erneuter Versuch — manuell
  angestossen oder beim nächsten Zeitgeber-Lauf — erfolgreich war. Im geführten Weg gibt es
  überhaupt keinen automatischen Versuch, dessen Wiederholung diesen Zustand beenden könnte; er
  bleibt bestehen, bis der Betreiber selbst tätig wird — ohne jede zeitliche Grenze und ohne
  erneute Erinnerung ausser dem einmaligen Protokolleintrag.
- **Ein technisch noch gekoppeltes, aber widerrufenes Gerät kann weiterhin Indexankündigungen
  senden**, die die Syncthing-Instanz des Anbieters empfangen und verarbeiten muss, auch wenn sie
  folgenlos bleiben — ein Ressourcenverbrauchspfad, der erst mit dem tatsächlichen Abbau der
  Ordnerfreigabe endet, unabhängig davon, wie schnell der Anfrageweg selbst geschlossen wurde.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn mehrere Geräte je Freund tatsächlich gebraucht werden — dann als eigene, explizite
  Beziehung zwischen Nutzer und Geräten modellieren, nicht nachträglich an die Identitäten
  anhängen.
- Wenn sich zeigt, dass der geführte Weg in der Praxis regelmässig zu lange im Zustand „Widerruf
  unvollständig" verharrt — dann wäre eine aktive Erinnerung (statt eines einmaligen
  Protokolleintrags) nachzurüsten.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Bedrohungsanalyse | `docs/research/2026-08-13-tesserarr-bedrohungsanalyse.md`, Befund 1 |
| Konzept | `docs/site/concepts/tesserarr.md`, Abschnitte „Widerruf und die Syncthing-Kopplung" und „Zugang zum Dienst ist nicht Zugang zum Transport" |
