# ADR-0006: Oberflächenprüfung als hartes Tor

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md), [ADR-0011](0011-design-system-werkstatt.md)

## Kontext

Tesserarr ist das erste Projekt im Bestand mit einer nennenswerten eigenen Weboberfläche.
Bisher gibt es dafür keinen Skill — Playwright ist ausschliesslich als **Zugriffswerkzeug** für
authentifizierte HomeLab-Dienste beschrieben (`.claude/rules/web-access-policy.md`), nicht als
Prüfwerkzeug für die eigene Softwareentwicklung. Für die Frage, wie und wann die Oberfläche
geprüft wird, standen mehrere Ausgestaltungen zur Wahl.

**Kein automatisiertes Tor, nur manuelle Prüfung vor einem Release.** Am wenigsten Aufwand,
aber verlässt sich vollständig auf die Aufmerksamkeit eines Menschen zu einem bestimmten
Zeitpunkt — bei jeder kleinen Änderung dazwischen fehlt jede Rückmeldung.

**Prüfung nur bei Änderungen an Oberflächen-Dateien auslösen.** Naheliegend, um Prüfzeit zu
sparen. Verworfen, weil der gefährlichste Fall genau **nicht** dieser ist: Eine Änderung im
Kern — etwa an der öffentlichen Schnittstelle `/api/v1`, dem einzigen Datenweg der Oberfläche
(siehe [ADR-0005](0005-go-kern-getrennte-oberflaeche-gemeinsames-abbild.md)) — kann die
Oberfläche brechen, ohne dass eine einzige Oberflächen-Datei angefasst wurde. Ein
änderungsgetriggerter Lauf würde genau diesen Fall übersehen.

**Pixelgenaue Bildvergleiche als Prüftor.** Objektiv und auf den ersten Blick naheliegend für
eine visuelle Oberfläche. Verworfen: Solche Vergleiche werden bei jeder
Schriftart-Aktualisierung, jeder Sub-Pixel-Rendering-Änderung des Betriebssystems oder jeder
geringfügigen Bibliotheksaktualisierung rot — unabhängig davon, ob tatsächlich ein Fehler
vorliegt. Ein Prüftor, das regelmässig ohne echten Grund rot wird, verliert seine
Glaubwürdigkeit und wird über kurz oder lang abgeschaltet oder ignoriert — dann ist es kein Tor
mehr, sondern nur noch ein stiller Kostenpunkt.

Der ausschlaggebende Punkt für das gewählte Modell: Ein grüner Testlauf der Schnittstellen
(`go test`) beweist, dass der Kern liefert — er beweist **nicht**, dass die Seite überhaupt
erscheint. Ein Fehler beim Start der Oberfläche liefert eine weisse Seite und gleichzeitig
einen grünen Testlauf auf Kern-Ebene.

## Entscheidung

1. Es wird ein neuer Skill **`ui-verification`** angelegt, der die Prüfung der Oberfläche mit
   Playwright und Chrome beschreibt.
2. Geprüft wird über eine **feste Auswahl von Ansichten** (Anmeldung, Katalog, Titel-Detail,
   eigene Anfragen, Verwaltung), in **drei Breiten** (360, 768, 1440 — Smartphone, Tablet,
   Desktop) und in **beiden Themen** (hell, dunkel).
3. Prüfpunkte, jeder für sich blockierend: Die Seite rendert überhaupt; **null**
   Konsolen-Fehler; keine fehlgeschlagenen Netzwerk-Anfragen; **kein** waagerechtes Scrollen in
   keiner der drei Breiten; sichtbarer Tastatur-Fokus; ausreichende Kontrastwerte.
4. Der Lauf greift bei **jedem** Antrag (Pull Request), nicht nur bei Änderungen an
   Oberflächen-Dateien — aus dem im Kontext genannten Grund, dass gerade Kern-Änderungen die
   Oberfläche unbemerkt brechen können.
5. **Ausdrücklich kein Tor:** pixelgenaue Bildvergleiche. Bildschirmfotos werden weiterhin als
   Artefakt jedes Prüflaufs abgelegt — sie informieren, blockieren aber nichts.

## Konsequenzen

**Positiv**

- Der gefährlichste Fall — eine Kern-Änderung, die die Oberfläche unbemerkt bricht, ohne dass
  eine Oberflächen-Datei angefasst wurde — wird zuverlässig erfasst, weil der Lauf an jedem
  Antrag hängt, nicht an einem Datei-Muster.
- Alle Prüfpunkte sind **objektiv messbar** (Konsolen-Fehler, Netzwerk-Status, Scroll-Breite,
  Kontrastwert) statt eines Geschmacksurteils — kein Streit darüber, ob ein Test zu Recht
  fehlschlägt.
- Ohne Pixel-Vergleich entsteht kein Wartungsalptraum durch regelmässige, grundlose
  Fehlschläge bei jeder Schriftart- oder Bibliotheksaktualisierung — das Tor bleibt über die
  Zeit glaubwürdig.

**Negativ**

- **Jeder** Antrag bezahlt die Laufzeitkosten eines vollständigen, browsergestützten Testlaufs
  über mehrere Ansichten, Breiten und Themen — auch ein Antrag, der ausschliesslich eine
  Backend-Funktion ohne jeden erkennbaren Oberflächenbezug ändert. Das ist ein bewusst in Kauf
  genommener Mehraufwand an Rechenzeit gegenüber einem änderungsgetriggerten Lauf.
- **Ohne Pixel-Vergleich bleiben rein gestalterische Regressionen unentdeckt**, solange
  Konsole, Netzwerk, Scroll-Verhalten, Tastatur-Fokus und Kontrast unauffällig sind: eine
  falsche Farbe, ein verrutschtes Element, ein kaputtes oder fehlendes Symbol, eine verzerrte
  Kachel — all das kann ein grün laufendes Tor durchlassen. Das Tor sichert
  **Funktionsfähigkeit**, nicht **Gestaltungstreue**; das ist eine bewusste Grenze, keine
  Lücke, die übersehen wurde, aber es bleibt eine Grenze.
- Der neue Skill muss erst geschrieben, erprobt und danach gepflegt werden — eine zusätzliche
  Wartungsfläche, die bei den bisherigen reinen Go-Projekten ohne eigene Oberfläche nicht
  existierte.
- Die **feste Ansichtsauswahl** kann veralten: Entsteht eine neue Ansicht (etwa mit 0.1.0 oder
  0.2.0, wenn der Medien-Weg tatsächlich implementiert wird), gibt es keinen automatischen
  Mechanismus, der sie der Prüfliste hinzufügt. Ohne bewusste Pflege wächst eine Lücke zwischen
  dem, was die Oberfläche tatsächlich zeigt, und dem, was das Tor tatsächlich prüft.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn sich zeigt, dass die Laufzeitkosten eines Vollprüflaufs bei jedem Antrag in der Praxis
  untragbar werden — dann wäre eine Abwägung zwischen vollständiger Abdeckung und
  änderungsgetriggerter Prüfung neu zu treffen.
- Wenn sich in der Praxis zeigt, dass gestalterische Regressionen tatsächlich regelmässig
  unentdeckt bis zur Auslieferung durchrutschen — dann wäre zu prüfen, ob ein informativer
  (nicht blockierender) Bildvergleich als Ergänzung sinnvoll ist, ohne die Blockierlogik dieses
  ADR zu ändern.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitte „Grundentscheidungen", „Bedienbarkeit auf allen Geräten" und „Qualitätssicherung" |
| Web-Zugriff-Policy (Playwright bisher nur als Zugriffswerkzeug) | `.claude/rules/web-access-policy.md` |
