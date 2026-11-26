---
title: Medienfreigaben und Freigaberegeln
description: Medienfreigaben, Freigaben auf beliebiger Ordnertiefe und wie ein Pfad geprüft wird.
---

Eine **Medienfreigabe** ist ein Wurzelordner an einem Ausführungsort: einem Satelliten, oder der Zentrale selbst, wenn sie
die Dateien sieht. Du gibst Ordner einer Freigabe auf beliebiger Tiefe frei, für alle (Standardfreigabe) oder für einen
Nutzer.

- Jeder Katalogzugriff läuft durch **eine** Freigabeprüfung: Durchsehen, Suche und später Anfragen.
- Die Prüfung arbeitet am **aufgelösten, echten Pfad**: kein `..`, kein symbolischer Link aus der Freigabe hinaus. Im
  Zweifel lautet die Antwort nein.
- Hardlinks gehen nur innerhalb eines Dateisystems, deshalb bekommt jede Medienfreigabe ihren eigenen Ausgangsbereich
  (Entscheidung 0003).

**Stand:** Medienfreigaben, Freigaberegeln und der Ordnerindex kommen mit 0.1.0.
