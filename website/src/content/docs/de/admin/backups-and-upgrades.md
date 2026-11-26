---
title: Sicherungen und Aktualisierungen
description: Die Sicherung vor jeder Migration und wie eine Aktualisierung abläuft.
---

Eine Aktualisierung ist ein neuer Image-Tag. Beim Start führt der einmalige Container `migrate` den Befehl
`artisan tesserarr:migrate` aus:

1. Stehen Migrationen an, schreibt er zuerst eine Sicherung mit `pg_dump` nach `TESSERARR_BACKUP_DIR` und behält die
   neuesten `TESSERARR_BACKUP_KEEP` (standardmäßig fünf).
2. Schlägt die Sicherung fehl oder ist sie leer, hört er auf, ohne zu migrieren.
3. Erst dann migriert er. Steht nichts an, gibt es keine Sicherung.

`web`, `worker` und `scheduler` starten, nachdem `migrate` erfolgreich fertig ist. Migrationen gehen nur vorwärts; zurück
geht es über die Sicherung, eingespielt mit dem Image der Version, die sie geschrieben hat. Die Variablen stehen unter
[Konfiguration](/tesserarr/de/install/configuration/).

Leg das Sicherungsverzeichnis auf ein Volume, das deine eigene Sicherung erfasst: Die Dumps enthalten die ganze Datenbank.

## Offene Tabs

Ein Tab, der während einer Aktualisierung offen ist, behält die Oberfläche der alten Version. Er bemerkt die neue Version
an der nächsten Antwort des Servers und zeigt **Neue Version verfügbar**, ohne von selbst neu zu laden, damit nichts
verloren geht, was gerade eingegeben wird. Die nächste Seite, die jemand öffnet, lädt die neue Version ohnehin. Siehe
[Version und Aktualisierungen](/tesserarr/de/using/updates/).
