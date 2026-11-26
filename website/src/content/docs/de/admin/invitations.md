---
title: Einladungen
description: Freunde einladen, eine Einladung widerrufen und den Zugang zu einem bestehenden Konto wiederherstellen.
---

Jedes Konto beginnt mit einer Einladung (Entscheidung 0004). Du erzeugst sie in der Weboberfläche, mit `artisan` oder über
die API und gibst den Link weiter, wie du willst, per Mail, wenn du Mail eingerichtet hast.

- Eine Einladung gilt **einmal**, für begrenzte Zeit (standardmäßig sieben Tage), und lässt sich widerrufen, bis sie
  eingelöst ist.
- Gespeichert wird nur ihr Hash; eine abgeflossene Datenbank verrät also keinen brauchbaren Link.
- Lösen zwei Menschen denselben Link gleichzeitig ein, gelingt es genau einem.
- Eine **Wiederherstellungs-Einladung** bindet einen neuen Anmeldeweg an ein **bestehendes** Konto. Freigaben,
  Kontingente, das Protokoll und die Syncthing-Kopplung bleiben. Nutze sie, wenn jemand jeden Passkey und Faktor verloren
  hat.

**Stand:** Einladungen kommen mit 0.1.0.
