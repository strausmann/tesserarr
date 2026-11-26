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

## Der Katalog

| Recht | Was es erlaubt |
| --- | --- |
| `users.view` | Die Nutzerliste sehen |
| `users.manage` | Die Rolle eines Kontos ändern, Konten sperren und entsperren |
| `roles.manage` | Eigene Rollen anlegen, ändern und löschen |
| `invitations.create`, `invitations.revoke` | Einladungen erzeugen und widerrufen |
| `tokens.manage_own`, `tokens.manage_all` | Die eigenen API-Token verwalten oder die aller Konten |
| `login_methods.manage` | Die Anmeldewege einrichten |
| `shares.manage`, `grants.manage` | Medienfreigaben verwalten, Freigaben vergeben |
| `catalog.browse`, `catalog.view_all` | Durchsuchen, was mit dir geteilt ist, den ganzen Katalog sehen |
| `connections.view`, `connections.manage` | Die Verbindungen zu den Arr-Diensten, Plex und Syncthing sehen und verwalten |
| `satellites.manage` | Satelliten registrieren und widerrufen |
| `status.view` | Die Statusseite sehen |
| `audit.view` | Das Protokoll lesen |
| `settings.manage` | Die Einstellungen ändern |
| `satellite.execute` | Aufträge ausführen; nur für Satelliten-Token, nie für Konten |

*Betreiber* hat jedes Recht außer `satellite.execute`; *Nutzer* hat `catalog.browse` und `tokens.manage_own`. Keine der
beiden vorgegebenen Rollen lässt sich ändern oder löschen, auch nicht mit `roles.manage`. Jedes Recht außer
`catalog.browse` und `tokens.manage_own` gilt als Verwaltungsrecht: Ein Konto mit einem davon sieht die Navigation des
Betreibers.

## Eigene Rollen

Unter *Rollen und Rechte* legst du eine Rolle an, kreuzt ihre Rechte an und speicherst. Ankreuzen kannst du nur Rechte,
die du selbst hast, und ändern oder löschen kannst du eine Rolle nur, wenn du alle ihre Rechte hast. Löschst du eine
Rolle, bekommen ihre Konten die Rolle *Nutzer*. Jede Änderung wird mit den hinzugefügten und entfernten Rechten
protokolliert.

## Wie ein Recht geprüft wird

Eine einzige Funktion entscheidet jedes Recht, auf Seiten, in der API und auf der Befehlszeile (Entscheidung 0016). Sie
erlaubt, wenn das Konto das Recht **jetzt** hat und nicht gesperrt ist, und bei einer Anfrage mit API-Token zusätzlich,
wenn das Token es trägt. Eine Herabstufung oder Sperre gilt ab der nächsten Anfrage; niemand muss sich neu anmelden.

Die [API-Referenz](/tesserarr/de/reference/api/) nennt für jede Operation das nötige Recht.
