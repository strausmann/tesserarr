---
title: Rollen und Token
description: Ein Rechtekatalog für Rollen und API-Token und die Regeln, die ihn schützen.
---

Rollen und API-Token schöpfen aus **einem** Rechtekatalog (Entscheidungen 0007 und 0016).

- **Rollen.** *Betreiber* hat jedes Recht, *Nutzer* die, die ein Freund braucht; eigene Rollen liegen dazwischen. Es kann
  mehrere Betreiber geben.
- **Schutzregeln.** Der letzte Betreiber kann sich nicht entfernen oder herabstufen; niemand vergibt ein Recht, das er
  nicht hat; jede Rechteänderung wird protokolliert.
- **API-Token** tragen die Rechte, die für sie ausgewählt wurden, mit Vorgaben für „Arr-Anbindung“, „Satellit“ und
  „Einladungen erzeugen“. Ein Token kann nie mehr als sein Besitzer *jetzt*: Wird der Besitzer herabgestuft, verlieren
  seine Token mit.
- Token werden als Hash gespeichert, zeigen ihre letzte Nutzung, können ablaufen und lassen sich jederzeit widerrufen.

Die [API-Referenz](/tesserarr/de/reference/api/) nennt für jede Operation das nötige Recht.

**Stand:** Rollen und Token kommen mit 0.1.0.
