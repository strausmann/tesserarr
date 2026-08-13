# ADR-0003: Hardlink bevorzugt, Kopie als Rückfall

**Status:** accepted
**Datum:** 2026-08-13
**Ersetzt:** —
**Ersetzt durch:** —
**Überarbeitet:** —
**Überarbeitet durch:** —
**Verwandt:** [ADR-0001](0001-anbieter-seitiger-einzeldienst.md), [ADR-0008](0008-widerruf-wirkt-auf-beide-wege-kopplung-am-nutzer.md)

## Kontext

Wird eine Anfrage angenommen, muss die betroffene Mediendatei aus dem Bestand des Medienservers
in den Syncthing-überwachten Ausgangsordner (`outgoing/<anfrage>/`) gelangen, damit sie zum
Empfänger übertragen wird. Drei Wege standen zur Wahl:

**Symlink.** Ein symbolischer Verweis auf die Originaldatei — der geringste Aufwand, kein
zusätzlicher Speicherplatz. Scheidet aus einem technischen Grund aus, der nicht verhandelbar
ist: Syncthing folgt Symlinks **nicht**, sondern überträgt den Verweis selbst als Datei. Beim
Empfänger käme damit ein toter Verweis auf einen Pfad an, der auf seinem System nicht existiert
— vollständig unbrauchbar.

**Ausschliesslich Kopie.** Eine vollständige Kopie der Datei in den Ausgangsordner. Funktioniert
immer, unabhängig von Dateisystemgrenzen, kostet aber bei jeder offenen Anfrage die volle
Dateigrösse ein zweites Mal — bei einer Staffel in hoher Auflösung schnell im dreistelligen
Gigabyte-Bereich, für die gesamte Dauer bis zur Abholung oder bis die Karenzzeit greift.

**Hardlink, Kopie nur als Rückfall.** Ein Hardlink ist ein vollwertiger zweiter
Verzeichniseintrag auf dieselben Daten desselben Dateisystems — kein zusätzlicher
Speicherbedarf, keine Kopierzeit. Er funktioniert nur **innerhalb** desselben Dateisystems; über
Dateisystemgrenzen hinweg (unterschiedliche Mounts, unterschiedliche Datenträger) schlägt das
Anlegen fehl, und es bleibt nur die vollständige Kopie.

Der ausschlaggebende Punkt: In der Praxis wird die Kopie die Ausnahme sein — sofern Tesserarr
auf demselben Dateisystem wie die Mediendaten betrieben wird —, und der Hardlink macht den
Regelfall speicherplatzneutral.

**Ausdrücklich als offen benannt:** Ob Syncthing eine hartverknüpfte Datei tatsächlich wie eine
gewöhnliche Datei mit vollem Inhalt überträgt, ist zum Zeitpunkt dieser Entscheidung
**begründet erwartbar, aber nicht praktisch nachgewiesen**. Syncthing erkennt Dateien primär
über Pfad und Inhaltsprüfsumme, nicht über Inode-Identität — die Erwartung, dass es einen
Hardlink unauffällig wie eine normale Datei behandelt, stützt sich auf dieses bekannte
Scan-Verhalten, wurde aber nicht gegen eine laufende Instanz verifiziert. Diese Prüfung ist für
den Zeitraum vor 0.1.0 vorgesehen (siehe Entwurf, Abschnitt „Offene Punkte").

## Entscheidung

1. Der Baustein `provision` versucht bei jeder Bereitstellung **zuerst** einen Hardlink von der
   Mediendatei nach `outgoing/<anfrage>/`.
2. Schlägt das Anlegen fehl (typischerweise, weil Quelle und Ziel auf unterschiedlichen
   Dateisystemen liegen), fällt `provision` auf eine **vollständige Kopie** zurück.
3. Der tatsächlich gewählte Weg und der dadurch belegte Speicherplatz werden je Anfrage im
   Baustein `audit` protokolliert — sichtbar, welcher Anteil der offenen Anfragen den teuren
   Kopie-Pfad genommen hat.
4. Beim Aufräumen nach Ablauf der Karenzzeit (oder nach Abholung) gilt symmetrisch: Ein
   Hardlink-Eintrag wird entfernt, ohne die Originaldatei zu berühren; eine Kopie wird gelöscht
   und gibt den belegten Platz frei.
5. Damit ist der Hardlink-Pfad **keine reine Optimierung**, sondern bestimmt praktisch, wo
   Tesserarr betrieben werden kann: Nur ein Knoten, der dasselbe Dateisystem wie die
   Mediendaten sieht, vermeidet den teuren Kopie-Fall im Regelbetrieb. Diese Einschränkung ist
   bereits im begleitenden Konzept als Kriterium für die Node-Zuweisung festgehalten.

## Konsequenzen

**Positiv**

- Im Regelfall (gleiches Dateisystem) entsteht **kein** zusätzlicher Speicherbedarf und keine
  Kopierzeit — die Bereitstellung ist praktisch sofort abgeschlossen.
- Die Aufräum-Semantik ist für beide Wege einfach und eindeutig: Verzeichniseintrag entfernen
  oder Datei löschen, ohne Sonderfallbehandlung.
- Das Protokoll macht sichtbar, wie oft der teure Pfad genommen wurde — eine messbare
  Betriebsgrösse statt einer Vermutung.

**Negativ**

- **Diese Entscheidung erzwingt eine Einschränkung bei der Knotenwahl**, die mit dem
  eigentlichen Zweck des Dienstes (Medien teilen) nichts zu tun hat: Tesserarr kann nicht frei
  auf einem beliebigen, gut ausgelasteten Knoten laufen, sondern nur dort, wo die Mediendaten
  liegen bzw. wo dasselbe Dateisystem eingehängt ist. In Mehrknoten-Umgebungen ist das eine
  reale Betriebseinschränkung, nicht nur eine theoretische.
- **Das Verhalten von Syncthing bei hartverknüpften Dateien ist nicht praktisch
  nachgewiesen.** Sollte sich zeigen, dass Syncthing Hardlinks anders behandelt als erwartet —
  etwa, weil es sie über Inode-Identität dedupliziert und dabei fälschlich als bereits
  übertragen einstuft, oder weil es beim Scannen an der Verzeichnisebene abweichendes Verhalten
  zeigt —, ist diese gesamte Entscheidung auf tönernen Füssen gebaut und müsste vor 0.1.0
  revidiert werden.
- **Geteilte Daten bergen ein Konsistenzrisiko.** Ein Hardlink verweist auf **dieselben** Daten
  wie das Original, nicht auf eine Momentaufnahme. Wird die Originaldatei während einer
  laufenden Übertragung von einem anderen Prozess verändert — etwa einem Werkzeug, das
  nachträglich Metadaten in die Datei schreibt —, ändert sich der Inhalt auch auf der
  Hardlink-Seite, während die Übertragung möglicherweise bereits läuft. Bei einer reinen Kopie
  wäre dieses Risiko ausgeschlossen, weil zum Kopierzeitpunkt ein unabhängiger Datenstand
  entsteht.
- Das Verhalten von Hardlinks unterscheidet sich zwischen Dateisystemen (z. B. bei
  Copy-on-Write-Systemen gegenüber klassischen Dateisystemen wie ext4) — diese Entscheidung
  wurde nicht gegen jedes im Bestand vorkommende Dateisystem im Detail geprüft.
- Der Kopie-Rückfall hat **keine definierte Obergrenze**. Liegen Tesserarr und die Mediendaten
  entgegen der Empfehlung doch auf unterschiedlichen Dateisystemen, kann eine einzelne offene
  Anfrage unbegrenzt Speicherplatz binden, solange sie weder abgeholt wird noch verfällt.

**Wann diese Entscheidung neu zu bewerten ist**

- Sobald die Prüfung vor 0.1.0 zeigt, dass Syncthing Hardlinks nicht wie erwartet als
  vollwertige Dateien überträgt — dann ist eine grundsätzlich andere Bereitstellungsstrategie
  nötig, nicht nur eine Anpassung.

## Referenzen

| Dokument | Fundstelle |
|---|---|
| Entwurf | `docs/superpowers/specs/2026-08-13-tesserarr-design.md`, Abschnitte „Grundentscheidungen", „Bereitstellung und Aufräumen" und „Offene Punkte" |
| Konzept | `docs/site/concepts/tesserarr.md`, Abschnitte „Node-Zuweisung" und „Ressourcen-Bedarf" |
| Syncthing REST-Schnittstelle, Fortschritt | `https://docs.syncthing.net/rest/db-completion-get.html` |
