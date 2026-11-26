---
title: Protokoll
description: Jede Verwaltungsaktion, aus der Weboberfläche, über die API und von der Befehlszeile.
---

Tesserarr protokolliert jede Verwaltungsaktion: Einladungen, Anmeldewege, Rollen, Token, Freigaben und
Wiederherstellungen, egal wer sie auslöst und wie (Weboberfläche, API oder `artisan`). Das Protokoll wird nur ergänzt,
nie geändert, und nennt Menschen über ihre Nutzer-ID, nicht über ihre E-Mail-Adresse. Satelliten schreiben kein eigenes
Protokoll: Sie melden Ergebnisse, und die Zentrale protokolliert sie (Entscheidung 0009).

## Was ein Eintrag enthält

| Feld | Inhalt |
| --- | --- |
| Zeit | Wann es geschah |
| Wer und Herkunft | Das Konto (mit seinem aktuellen Namen) oder die Konsole mit dem Betriebssystem-Nutzer, der `artisan` ausgeführt hat; Herkunft ist *Oberfläche*, *CLI* oder *API* |
| Aktion | Was getan wurde, mit seinem Schlüssel, zum Beispiel `role.update` |
| Ergebnis | *Erledigt*, oder *Abgelehnt*, wenn eine Schutzregel es verhindert hat (ein fehlendes Recht, der letzte Betreiber) |

Ein Eintrag enthält nie ein Geheimnis: Das Protokoll lehnt jede Angabe ab, deren Name nach einem klingt (Token,
Passwort, Schlüssel), und schreibt den Eintrag dann gar nicht. Einstellungen werden mit ihrem Namen protokolliert, nie
mit ihrem Wert.

## Nur ergänzen

Die Datenbank selbst verweigert, einen Eintrag zu ändern oder zu löschen: Ein Trigger auf der Tabelle lehnt jedes
`UPDATE` und `DELETE` ab.

## Das Protokoll lesen

*Protokoll* in der Navigation (Recht `audit.view`) zeigt zehn Einträge je Seite, die neuesten zuerst, mit *Neuere* und
*Ältere*. Filtern lässt sich nach Zeitraum (24 Stunden, 7 Tage, 30 Tage), nach Person oder Konsole, nach Aktion und
nach Herkunft.
