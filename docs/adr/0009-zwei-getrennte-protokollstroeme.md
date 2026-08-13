# ADR-0009: Zwei getrennte Protokollströme

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0007](0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md)

## Kontext

Tesserarr braucht Protokollierung für zwei grundverschiedene Zwecke: maschinelle
Sicherheitsauswertung (CrowdSec, das jeden HTTP-Zugriff sieht) und menschliche Fehlersuche im
Betrieb (der Betreiber, der wissen will, was der Dienst entschieden und getan hat). Diese beiden
Zwecke stellen einander widersprechende Anforderungen an dasselbe Protokoll: Das eine muss
**unveränderlich** und in einem von Standardwerkzeugen erkannten **Fremdformat** vorliegen, damit
fertige Auswertungsregeln greifen. Das andere muss **strukturiert** und mit **Bedeutungsstufen**
versehen sein, um Ursachen zu unterscheiden, die von aussen identisch aussehen.

**Verworfene Varianten:**

1. **Ein gemeinsamer Strom** für beides. Verworfen: Vermischt sind beide Anforderungen
   unvereinbar — die Sicherheitsauswertung bräuchte eigene Filter, um das Fremdformat aus einem
   um Tesserarr-Felder erweiterten Strom herauszulösen, und die betriebliche Fehlersuche würde in
   der reinen HTTP-Zeilenflut ersticken, ohne die Bedeutungsstufen, die eine Ablehnung von einem
   echten Fehler unterscheiden.
2. **Ein eigenes, für Tesserarr massgeschneidertes Zugriffsprotokoll-Format** statt eines
   etablierten Fremdformats. Verworfen: Für ein eigenes Format gibt es keine fertigen
   Auswertungsregeln — die Auswertung müsste selbst geschrieben und bei jeder Erweiterung des
   Formats nachgezogen werden. Für das kombinierte nginx-Format existieren sie bereits.
3. **Dieselbe Auswertungsregel gegen wiederholte Anmeldefehler unverändert auf alle Pfade
   anwenden**, also auch auf die Token-Schnittstelle für die Arr-Anbindung
   ([ADR-0002](0002-usenet-nachbildung-statt-torrent.md)). Verworfen — ein Kategorienfehler: Die
   Regel ist für eine Anmeldemaske mit menschlichem Bediener gedacht, der auf eine Rückfrage
   reagieren kann. Ein falsch eingerichtetes Fremdsystem (etwa ein Prowlarr mit vertipptem Token)
   erzeugt in Minuten dutzende Ablehnungen ohne jeden Angriff — und die übliche Gegenmassnahme,
   eine Rückfrage im Browser, kann eine Maschine nicht beantworten; sie bekäme eine HTML-Seite mit
   Statuscode 200 und meldete einen für den Betreiber unverständlichen Fehler. **Genau dieses
   Fehlerbild hat im Bestand bereits mehrere Tage Fehlersuche gekostet — an einem anderen Dienst
   mit derselben Konstellation.**

## Entscheidung

1. Ein **Zugriffsprotokoll im nginx-Format** — unveränderlich, weil fertige Auswertungsregeln
   darauf aufsetzen. Ein eigenes Format hiesse, die Auswertung selbst zu schreiben und bei jeder
   Änderung nachzuziehen.
2. Ein davon **getrenntes Aktionsprotokoll** mit Bedeutungsstufen für das, was der Dienst
   entschieden und getan hat. Vermischt sind beide unbrauchbar.
3. Im Zugriffsprotokoll steht die **echte Absenderadresse**, nicht die des vorgelagerten
   Dienstes — sonst sperrt die Sicherheitsschicht bei einem Angriff den eigenen Reverse Proxy und
   damit alle Nutzer gleichzeitig.
4. **Ablehnungen bekommen eine eigene Stufe.** Vier Ursachen sehen von aussen identisch aus —
   nicht freigegeben, Kontingent erschöpft, Titel nicht gefunden, Name nicht erzeugbar.
5. **Die Regel gegen wiederholte Anmeldefehler wird für die Token-Pfade ausgenommen.** Ein falsch
   eingerichtetes Fremdsystem erzeugt in Minuten dutzende Ablehnungen ohne Angriff; die übliche
   Gegenmassnahme ist eine Rückfrage im Browser, die eine Maschine nicht beantworten kann. Dieses
   Fehlerbild hat im Bestand bereits Tage Fehlersuche gekostet — an einem anderen Dienst mit
   derselben Konstellation. Für den Sitzungspfad bleibt die Regel aktiv.
6. **Kein Geheimnis in einem der beiden Ströme, auch nicht gekürzt.** Fehlertexte fremder Systeme
   werden eingeordnet, nicht durchgereicht — sie enthalten regelmässig genau die Werte, die hier
   nicht stehen dürfen.

## Konsequenzen

**Positiv**

- Die etablierte, fertige Sicherheitsauswertung (CrowdSec-Parser für das nginx-Format) ist direkt
  nutzbar, ohne sie selbst nachzubauen und bei jeder Formatänderung zu pflegen.
- Ein betrieblich lesbares, stufenklassifiziertes Protokoll für Menschen entsteht unabhängig vom
  starren Fremdformat des Zugriffsprotokolls.
- Ablehnungen als eigene Stufe beantworten die häufigste Betreiberfrage direkt („Warum bekommt
  mein Freund das nicht?"), statt sie aus vier äusserlich identischen Fällen erst rekonstruieren
  zu müssen.
- Die Token-Pfad-Ausnahme vermeidet einen konkret bereits erlebten, tagelangen Fehlersuchweg — sie
  ist keine theoretische Vorsichtsmassnahme, sondern eine dokumentierte Wiederholung eines
  bekannten Vorfalls.
- Kein Geheimnis in beiden Strömen bedeutet: Beide dürfen an Orte gelangen, die Tesserarr selbst
  nicht kontrolliert (Sicherungen, externe Log-Aggregation), ohne dass damit ein Zugang mitgeht.

**Negativ**

- **Zwei Ströme heissen zwei Ablagen, zwei Aufbewahrungsfristen, zwei Wege etwas zu
  übersehen.** Und das feste Fremdformat begrenzt, was im Zugriffsprotokoll überhaupt stehen
  kann.
- **Konkrete Folge der Formatbegrenzung: Es gibt keine gemeinsame Vorgangskennung zwischen
  beiden Strömen.** Das nginx-Format kennt kein Feld für Tesserarrs eigene, im Aktionsprotokoll
  durchgängig mitgeführte Vorgangskennung der Anfrage. Eine konkrete Zeile im Zugriffsprotokoll
  lässt sich einer Kette von Aktionsprotokoll-Einträgen deshalb nur **heuristisch** zuordnen —
  über Zeitstempel, Absenderadresse und Pfad —, nicht über einen garantierten, eindeutigen
  Schlüssel. Genau bei der Fehlersuche, für die das Aktionsprotokoll gedacht ist, ist das ein
  wiederkehrender Reibungspunkt.
- **Die Token-Pfad-Ausnahme ist nur so sicher wie die Annahme, auf der sie beruht: dass ein Token
  sich nicht sinnvoll erraten lässt.** Diese Annahme lebt strukturell **getrennt** von der
  Ausnahme selbst — die Token-Erzeugung liegt im Baustein `identity`/`invite`, die
  Brute-Force-Ausnahme in der Betriebs-/CrowdSec-Konfiguration. Würde die Token-Länge oder
  -Zufälligkeit später aus einem anderen Grund abgeschwächt (etwa für kürzere, leichter von Hand
  einzutragende Werte), gäbe es **keinen** automatischen Mechanismus, der die hier bewusst
  entfernte Bremse zurückbringt oder auch nur davor warnt — der Token-Pfad bliebe ungeschützt
  gegen genau die Art von Erratungsversuch, gegen die er zuvor durch die allgemeine Regel
  zufällig mitgeschützt war.
- **Getrennte Ströme bedeuten getrennte Lösch-/Bereinigungsdisziplin.** Muss zu einem Nutzer
  gehörenden Spuren nachgegangen werden — etwa im Rahmen eines Widerrufs
  ([ADR-0008](0008-widerruf-wirkt-auf-beide-wege-kopplung-am-nutzer.md)) —, liegen sie in zwei
  unabhängig strukturierten Speichern mit unterschiedlichem Format. Es ist leicht, bei einer
  Bereinigung nur einen der beiden Ströme zu berücksichtigen und den anderen zu übersehen, weil
  beide von unterschiedlichen Werkzeugen gelesen und unterschiedlich lange aufbewahrt werden.
- **Die Stufe „Fremdgespräch" protokolliert jeden Aufruf an ein Fremdsystem** — bei häufigem,
  routinemässigem Verkehr (etwa dem wiederholten Abfragen des Syncthing-Fortschritts während einer
  laufenden Übertragung) kann diese Stufe das Aktionsprotokoll mengenmässig dominieren und die
  selteneren, betrieblich wichtigeren Stufen „Fehler" und „Warnung" im Volumen untergehen lassen,
  wenn dafür keine gesonderte Aufbewahrungs- oder Filterregel existiert.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn sich in der Praxis zeigt, dass die fehlende gemeinsame Vorgangskennung die Fehlersuche
  regelmässig spürbar erschwert — dann wäre zu prüfen, ob ein zusätzliches, im nginx-Format
  zulässiges Freitextfeld (etwa im `<kennung>`-Feld des kombinierten Formats) die Vorgangskennung
  tragen kann, ohne die Kompatibilität mit fertigen Auswertungsregeln zu brechen.
- Wenn die Token-Erzeugung in `identity`/`invite` je geändert wird — dann ist die
  Brute-Force-Ausnahme für den Token-Pfad an dieser Stelle ausdrücklich mit zu prüfen, nicht
  stillschweigend als weiterhin gültig anzunehmen.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Konzept | `docs/site/concepts/tesserarr.md`, Abschnitt „Protokolle: zwei Ströme, getrennt gehalten" |
| Bedrohungsanalyse | `docs/research/2026-08-13-tesserarr-bedrohungsanalyse.md`, Befund 7 |
