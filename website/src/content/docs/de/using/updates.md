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

## Neuere Versionen (Betreiber, nur auf Wunsch)

Tesserarr kann dem Betreiber unter **Über Tesserarr** anzeigen, ob eine neuere Version erschienen ist. Die Prüfung ist
**standardmäßig ausgeschaltet**: Solange sie aus ist, verlässt nichts deine Instanz, und der Dialog sagt, dass die
Prüfung ausgeschaltet ist.

Einschalten lässt sie sich mit `TESSERARR_RELEASE_CHECK=true` (siehe
[Konfiguration](/tesserarr/de/install/configuration/)). Dann fragt der Scheduler der Zentrale alle sechs Stunden die
GitHub-Releases von Tesserarr nach dem neuesten Release, das keine Vorabversion ist. Ein Satellit fragt nie.

- **Was gesendet wird:** eine einfache `GET`-Anfrage mit dem User-Agent `Tesserarr`. Kein Token, kein Cookie, keine
  Adresse und keine Version deiner Installation.
- **Was verwendet wird:** nur die Versionsnummer der Antwort, und von der Antwort werden höchstens 64 KiB gelesen. Name,
  Text und Verweise des Releases werden ignoriert; den Verweis im Dialog baut Tesserarr selbst.
- **Ohne Netz:** Die Anfrage gibt nach fünf Sekunden auf, die letzte bekannte Antwort bleibt stehen, und in der
  Oberfläche schlägt nichts fehl.

Die erste Antwort kommt mit dem nächsten Lauf des Schedulers, das kann bis zu sechs Stunden nach dem Einschalten
dauern. Wer sie sofort haben will, führt die Prüfung einmal von Hand aus:

```bash
artisan tesserarr:release-check
```
