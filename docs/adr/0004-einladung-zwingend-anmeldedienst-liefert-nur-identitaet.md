# ADR-0004: Einladung zwingend, Anmeldedienst liefert nur die Identität

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0002](0002-usenet-nachbildung-statt-torrent.md), [ADR-0007](0007-anmeldung-sitzung-oberflaeche-token-fremdsysteme-getrennte-pfade.md), [ADR-0008](0008-widerruf-wirkt-auf-beide-wege-kopplung-am-nutzer.md)

## Kontext

Tesserarr braucht ein Modell dafür, wer Zutritt bekommt. Ein Anmeldedienst (generisches OIDC,
Plex, eine vorgelagerte Kopfzeilen-Anmeldung) beantwortet zuverlässig die Frage „wer ist das" —
er stellt eine geprüfte Identität fest. Er beantwortet **nicht** zwingend die Frage „darf diese
Person hier hinein". Für die Verknüpfung dieser beiden Fragen standen drei Modelle zur Wahl:

**Gruppenprüfung im Anbieter.** Der Anmeldedienst liefert Gruppen- oder Rollenzugehörigkeiten
mit, und Tesserarr entscheidet anhand dieser Gruppen über den Zutritt. Das scheitert an zwei
der vorgesehenen Anmeldewege: Plex liefert grundsätzlich **keine** Gruppenzugehörigkeiten — ein
Plex-Konto zu besitzen sagt nichts darüber aus, ob die Person zum Kreis der Eingeladenen gehört,
denn ein Plex-Konto hat praktisch jeder. Bei der Kopfzeilen-Anmeldung übernimmt ein
vorgelagerter Zugangsdienst die gesamte Anmeldung; ob dieser Dienst ein für Tesserarrs Zweck
brauchbares Gruppenkonzept mitbringt, ist eine zusätzliche, nicht kontrollierbare Annahme über
fremde Infrastruktur.

**Beides gleichzeitig** — Einladung **und** Gruppenprüfung parallel. Das verschiebt die
eigentliche Zutrittsentscheidung nicht wirklich in ein einziges, geprüftes Modell, sondern
verteilt sie auf zwei Stellen, die im Konfliktfall widersprüchliche Aussagen treffen können
(die Einladung sagt „ja", die Gruppe sagt „nein", oder umgekehrt) — zusätzliche Komplexität ohne
erkennbaren Sicherheitsgewinn, weil die Einladung allein bereits eine explizite, geprüfte
Positivliste ist.

**Einladung als alleiniges Zutrittskriterium**, Anmeldedienst liefert ausschliesslich die
Identität. Der Zutritt hängt an nichts, was ein fremdes System (der Anmeldedienst) mitliefert
oder eben nicht mitliefert — er ist vollständig in Tesserarrs eigener, geprüfter Datenhaltung
verankert.

## Entscheidung

1. Zutritt zu Tesserarr entsteht **ausschliesslich** über eine eingelöste Einladung. Es gibt
   keinen Weg, sich ohne Einladung zu registrieren, unabhängig davon, was der gewählte
   Anmeldedienst über die Person aussagt.
2. Der Anmeldedienst (Baustein `identity`) hat genau eine Aufgabe: die **Identität** einer
   Person feststellen (wer bist du). Über den Zutritt (darfst du hinein) entscheidet er nicht.
3. Eine Einladung (Baustein `invite`) ist ein Zufallswert mit mindestens 128 Bit, wird
   ausschliesslich als Hashwert gespeichert, ist **einmalig** einlösbar und trägt eine
   einstellbare Frist (Vorgabe 7 Tage). Der Versand erfolgt wahlweise per E-Mail oder als
   weiterzugebender Verweis — E-Mail ist ausdrücklich optional, damit der Dienst keine harte
   Abhängigkeit von einem konfigurierten Postausgang hat.
4. Bei erfolgreicher Anmeldung während der Einlösung wird die festgestellte Identität an die
   Einladung gebunden; die Einladung gilt danach als verbraucht. Ab diesem Zeitpunkt existiert
   ein Nutzer mit eigenem Kontingent, eigenen Freigaben und eigenem Protokoll.
5. Mehrere Identitäten je Nutzer sind vorgesehen (1:n) — wer sich zunächst über OIDC angemeldet
   hat, kann später zusätzlich Plex verbinden. Kontingent, Freigaben und Protokoll bleiben
   dabei an genau **einem** Nutzer, nicht an der einzelnen Identität.
6. Die Einlösung einer Einladung wird mit Identität, Anbieter des Anmeldewegs, Zeitpunkt und
   Absenderadresse protokolliert (Baustein `audit`), und eine Einladung ist jederzeit
   widerrufbar.

## Konsequenzen

**Positiv**

- Das Modell funktioniert **unabhängig davon**, was der jeweilige Anmeldedienst über
  Gruppenzugehörigkeit weiss oder nicht weiss — es bricht nicht an der Schwächsten der drei
  vorgesehenen Anmeldewege (Plex ohne Gruppen).
- Die Zutrittsentscheidung ist eine **explizite Positivliste** in Tesserarrs eigener
  Datenhaltung, nicht eine implizite Vertrauenskette in ein fremdes System, dessen
  Gruppenmodell sich ändern könnte, ohne dass Tesserarr davon erfährt.
- Widerruf ist jederzeit und ohne Abhängigkeit vom Anmeldedienst möglich.
- Die getrennte 1:n-Beziehung zwischen Nutzer und Identität erlaubt es, später zusätzliche
  Anmeldewege zu ergänzen, ohne Kontingent, Freigaben oder Protokoll-Historie neu aufzubauen.

**Negativ**

- **Bewusst getragenes Restrisiko (bereits im Entwurf benannt, hier bestätigt):** Bis zur
  Einlösung ist der Einladungsverweis selbst der Schlüssel. Wer ihn erlangt — durch
  Weiterleitung, ein kompromittiertes Postfach, einen Screenshot, ein informell weitergegebenes
  Chat-Zitat —, kann seine **eigene** Identität daran binden, ohne dass der eigentlich gemeinte
  Adressat je einlöst. Einmaligkeit, Frist, Widerruf und Protokoll mildern dieses Risiko, heben
  es aber nicht auf: In der kurzen Zeitspanne zwischen Versand und Einlösung bleibt es ein
  „wer den Verweis zuerst nutzt"-Modell.
- **E-Mail ist optional — das verschärft das Risiko oben in der Praxis eher, als es zu
  mildern.** Ohne konfigurierten Postausgang muss der Verweis über einen beliebigen, oft
  informellen Kanal (Chat, Messenger) weitergegeben werden. Ein solcher Kanal bietet
  typischerweise weniger Zustellsicherheit und mehr Gelegenheit für eine versehentliche
  Weiterverbreitung als ein direkt adressierter E-Mail-Versand.
- **Zusätzliche Verwaltungslast für den Betreiber.** Es gibt keine Selbstregistrierung — jede
  neue Person braucht eine manuell erzeugte, individuelle Einladung. Das ist für den
  vorgesehenen Kreis (befreundete Homelab-Betreiber) angemessen, schliesst aber jedes Szenario
  mit grösserem oder offenem Nutzerkreis von vornherein aus.
- Die Entscheidung überträgt die gesamte Zutrittslogik in Tesserarrs eigene Datenhaltung — ein
  Fehler in `invite` oder `store` (etwa eine zu schwache Zufallsquelle, ein Fehler beim
  Hash-Vergleich) wiegt dadurch schwerer, weil es keine zweite, unabhängige Prüfschicht
  (die verworfene Gruppenprüfung) gibt, die einen solchen Fehler abfangen könnte.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn Tesserarr für einen deutlich grösseren oder offeneren Nutzerkreis gedacht werden soll,
  für den manuell erzeugte Einzeleinladungen als Zugangsweg nicht mehr praktikabel sind.
- Wenn ein Anmeldeweg hinzukommt, dessen Gruppenmodell tatsächlich zuverlässig genug ist, um
  als zusätzliche, nicht aber alleinige Prüfschicht zu dienen.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitte „Grundentscheidungen", „Zugang" und „Datenmodell" |
| Konzept | `docs/site/concepts/tesserarr.md`, Abschnitt „Sicherheit" |
