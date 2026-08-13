# ADR-0001: Anbieter-seitiger Einzeldienst — der Empfänger installiert nichts

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0003](0003-hardlink-bevorzugt-kopie-als-rueckfall.md), [ADR-0008](0008-widerruf-wirkt-auf-beide-wege-kopplung-am-nutzer.md)

## Kontext

Tesserarr soll befreundeten Homelab-Betreibern erlauben, einander Teile ihrer Mediensammlung
zugänglich zu machen. Zwischen den Beteiligten besteht bereits eine funktionierende
Syncthing-Verbindung — der Transportweg ist also gelöst, bevor überhaupt ein neuer Dienst
entsteht. Offen war die Frage, **wer** diesen neuen Dienst betreibt.

Für die Verteilung der Software auf Anbieter- und Empfängerseite standen drei Varianten zur
Wahl:

**A — Beidseitiger Dienst mit eigenem Protokoll.** Sowohl Anbieter als auch Empfänger betreiben
Tesserarr; beide Instanzen sprechen ein eigens entworfenes Protokoll miteinander, um Anfragen zu
stellen und Fortschritt zu melden. Das wäre die vollständigste Lösung — sie bräuchte Syncthing
nicht einmal zwingend als alleinigen Transportweg.

**B — Anbieter plus schlanker Empfangs-Agent.** Der Anbieter betreibt den vollständigen Dienst,
der Empfänger installiert einen kleinen Zusatzprozess, der ausschliesslich den
Übertragungsstand seiner eigenen Syncthing-Instanz an den Anbieter zurückmeldet.

**C — Reiner Anbieter-Dienst.** Nur der Anbieter betreibt Tesserarr. Der Empfänger installiert
nichts Neues; er nutzt seine ohnehin vorhandene Syncthing-Instanz und, falls gewünscht, seine
Arr-Suite.

Der ausschlaggebende Punkt gegen A und B: Die Frage, die ein Empfangs-Agent oder ein eigenes
Protokoll beantworten müsste — „wie weit ist die Übertragung, ist die Datei beim Empfänger
angekommen, wurde sie dort gelöscht" — beantwortet **Syncthings eigene REST-Schnittstelle**
bereits vollständig, aus Sicht der Anbieter-Instanz (`GET /rest/db/completion`, siehe
`docs.syncthing.net/rest/db-completion-get.html`). Ein zweites Protokoll oder ein zusätzlicher
Agent wären Aufwand für eine Information, die ohne jede weitere Software bereits vorliegt. Und
jede Zusatz-Installation auf der Empfängerseite senkt die Wahrscheinlichkeit, dass der Dienst
von jemand anderem als dem Autor je eingesetzt wird — der Empfänger hat Syncthing und die
Arr-Suite in aller Regel bereits im Einsatz, alles darüber hinaus ist eine neue Hürde.

## Entscheidung

1. Tesserarr wird **ausschliesslich auf der Anbieterseite** betrieben. Es gibt keine
   Empfänger-Variante der Software, keinen Empfangs-Agenten, kein eigenes
   Übertragungsprotokoll.
2. Der Fortschritt einer laufenden Übertragung wird **aus der eigenen Syncthing-Instanz des
   Anbieters** gelesen (Baustein `syncthing`), nicht von einer Gegenstelle gemeldet.
3. Der Empfänger bedient sich über zwei Wege, die beide auf bereits vorhandener Software
   aufsetzen: die Weboberfläche von Tesserarr, oder seine eigene Arr-Suite, die Tesserarr über
   die Nachbildungen aus [ADR-0002](0002-usenet-nachbildung-statt-torrent.md) wie eine
   gewöhnliche Bezugsquelle behandelt.
4. Der tatsächliche Dateitransport bleibt vollständig bei Syncthing. Tesserarr steuert nur, was
   wann in welchem Ordner liegt.

## Konsequenzen

**Positiv**

- Keine Installationshürde auf der Empfängerseite — das ist der Unterschied zwischen einem
  Werkzeug, das ausschliesslich der Autor benutzt, und einem, das andere Homelab-Betreiber
  tatsächlich übernehmen.
- Kein zweites, selbst entworfenes Protokoll zu spezifizieren, zu versionieren und
  abwärtskompatibel zu halten.
- Der gemeldete Fortschritt ist **gemessen**, nicht geschätzt oder auf Vertrauen in eine
  Gegenmeldung angewiesen.
- Ein einziger Einsatzort vereinfacht Betrieb, Sicherung und das Sicherheits-Audit — es gibt
  nur eine Angriffsfläche, nicht zwei.

**Negativ**

- Tesserarr weiss über die Empfängerseite **nur das, was die eigene Syncthing-Instanz über den
  Gerätezustand des Empfängers berichtet.** Es gibt keinen Rückkanal für alles, was darüber
  hinausgeht — etwa eine Fehlermeldung des Empfängers („Datei ist beschädigt angekommen") oder
  eine bewusste Rückmeldung („diese Übertragung wird nicht mehr gebraucht"). Einzig verfügbare
  Signale sind die, die Syncthing selbst kennt: Fortschritt, Pause, fehlende Freigabe,
  Löschung.
- Die Unterscheidung zwischen den Zuständen `abgeholt` und `verfallen` hängt **zwingend** davon
  ab, dass der Syncthing-Ordner auf **beiden** Seiten *Send & Receive* ist. Stellt der Empfänger
  seine Seite auf *Send Only*, kommt seine Löschung nie zurück, und Tesserarr kann die beiden
  Zustände nicht mehr unterscheiden — eine Einrichtungsvoraussetzung, die ausserhalb von
  Tesserarrs eigener Kontrolle liegt und nur über Dokumentation, nicht über Code, durchsetzbar
  ist.
- Pausiert oder entfernt der Empfänger die Syncthing-Freigabe eigenmächtig, erfährt Tesserarr
  das nur indirekt (`remoteState` wird `paused`/`notSharing`) und kann nicht unterscheiden, ob
  das Absicht oder ein technisches Problem beim Empfänger ist.
- Diese Entscheidung bindet Tesserarr fest an Syncthing als einzigen Transportweg. Ein Wechsel
  auf ein anderes Transportmittel (z. B. eine direkte Übertragung) wäre kein Parameter, sondern
  eine grundlegende Neukonzeption des `syncthing`-Bausteins und der Zustandsübergänge.

**Wann diese Entscheidung neu zu bewerten ist**

- Wenn ein Rückkanal vom Empfänger nötig wird, den Syncthing strukturell nicht liefern kann
  (z. B. eine bewusste Bewertung oder Fehlermeldung durch den Empfänger).
- Wenn Tesserarr für Beteiligte gedacht werden soll, die **keine** bestehende
  Syncthing-Verbindung zueinander haben — dann entfiele die Grundannahme dieser Entscheidung
  vollständig.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitte „Grundentscheidungen" und „Syncthing-Anbindung" |
| Syncthing REST-Schnittstelle, Fortschritt | `https://docs.syncthing.net/rest/db-completion-get.html` |
