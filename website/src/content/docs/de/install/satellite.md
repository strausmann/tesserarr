---
title: Satellit
description: Die Dateiarbeit neben deinem Medienspeicher ausführen, ohne Datenbank und ohne offenen Port.
---

Ein Satellit ist dasselbe Image mit `TESSERARR_ROLE=satellite`. Er läuft dort, wo die Medien liegen, zum Beispiel auf
einem NAS neben Syncthing, und erledigt die Dateiarbeit, die die Zentrale beschließt: Hardlinks in den Ausgangsordner
(ab 0.2.0) und lesende Prüfungen.

- Er **kennt keine Nutzer und keine Freigaben**. Er bekommt eng gefasste Aufträge, die schon entschieden sind.
- Er **wählt sich nach außen**. Er holt seine Aufträge bei der Zentrale ab; auf seinem Rechner lauscht nichts für die
  Zentrale.
- Er hat **keine Datenbank und keine Queue**, nur ein eigenes, widerrufbares Token mit dem Recht `satellite.execute`.
- Er prüft **selbst**, dass jeder aufgelöste Pfad in einer ihm zugewiesenen Medienfreigabe liegt, als zweite,
  unabhängige Schranke.

Ein kompromittierter Satellit kostet diesen einen Rechner, nicht die Benutzerverwaltung.

**Stand:** Registrierung, Lebenszeichen und ein harmloser Prüfauftrag kommen mit 0.1.0. Die Variablen des Satelliten
landen dann unter [Konfiguration](/tesserarr/de/install/configuration/).
