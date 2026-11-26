---
title: Version und Aktualisierungen
description: Wo die laufende Version steht, was „Neue Version verfügbar“ bedeutet und was die Versionsprüfung sendet.
---

## Die laufende Version

Im Fuß der Navigation steht die Version, mit der Tesserarr läuft, zum Beispiel `Tesserarr 0.1.0 · Über und Lizenzen`.
Ein Klick darauf öffnet **Über Tesserarr**: die Version, die Lizenz mit einem Verweis auf den Quellcode und die Lizenzen
der Schriften.

## „Neue Version verfügbar“

Aktualisiert der Betreiber Tesserarr, während ein Tab offen ist, läuft in diesem Tab noch die Oberfläche der alten
Version. Jede Antwort des Servers nennt die Version, mit der er läuft, und der Tab vergleicht sie mit seiner eigenen.
Weichen sie ab:

- erscheint im Fuß der Navigation ein Kasten **Version … bereit**, und
- unten auf dem Bildschirm der Hinweis **Neue Version verfügbar**.

Nichts lädt von selbst neu, damit nichts verloren geht, was du gerade eingibst. **Neu laden** lädt die neue Version.
**Später** schließt den Hinweis für diese Version in allen Tabs des Browsers; der Kasten in der Navigation bleibt bis zum
Neuladen, und eine noch neuere Version öffnet den Hinweis wieder.

Ein offener Tab fragt den Server alle fünf Minuten nach seiner Version, außerdem wenn du zum Tab zurückkehrst und wenn
das Netz wieder da ist. Die nächste Seite, die du nach einer Aktualisierung öffnest, lädt die neue Version ohnehin.

## Neuere Versionen (Betreiber)

Unter **Über Tesserarr** sieht der Betreiber zusätzlich, ob eine neuere Version erschienen ist. Der Scheduler fragt alle
sechs Stunden die GitHub-Releases von Tesserarr nach dem neuesten Release, das keine Vorabversion ist. Die Anfrage
enthält kein Token, kein Cookie, keine Adresse und keine Version deiner Installation, nur den Namen `Tesserarr` als
User-Agent, und gibt nach fünf Sekunden auf. Ohne Netz bleibt die letzte bekannte Antwort stehen; in der Oberfläche
schlägt nichts fehl. Von der Antwort wird nur die Versionsnummer verwendet.

Ausschalten lässt sich die Prüfung mit `TESSERARR_RELEASE_CHECK=false` (siehe
[Konfiguration](/tesserarr/de/install/configuration/)). Tesserarr sendet und zeigt dann nichts zu Releases.
`artisan tesserarr:release-check` führt die Prüfung einmal von Hand aus.
