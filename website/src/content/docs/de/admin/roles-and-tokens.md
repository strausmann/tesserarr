---
title: Rollen und Token
description: Ein Rechtekatalog für Rollen und API-Token und die Regeln, die ihn schützen.
---

Rollen und API-Token schöpfen aus **einem** Rechtekatalog (Entscheidungen 0007 und 0016).

- **Rollen.** *Betreiber* hat jedes Recht, *Nutzer* die, die ein Freund braucht; eigene Rollen liegen dazwischen. Es kann
  mehrere Betreiber geben.
- **Schutzregeln.** Der letzte Betreiber kann sich nicht entfernen oder herabstufen; niemand vergibt ein Recht, das er
  nicht hat; jede Rechteänderung wird protokolliert.
- **API-Token** tragen die Rechte, die für sie ausgewählt wurden, mit Vorgaben für „Arr-Anbindung“ und „Einladungen
  erzeugen“ (Satelliten-Token kommen mit den Satelliten). Ein Token kann nie mehr als sein Besitzer *jetzt*: Wird der
  Besitzer herabgestuft, verlieren seine Token mit.
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

## API-Token

Unter *API-Token* (`/tokens`) erzeugst du Token für deine eigenen Skripte und Werkzeuge. Die Seite fragt vorher noch
einmal nach deinem Passwort. Gib dem Token einen Namen, wähle eine Vorgabe oder kreuze die Rechte selbst an und wähle,
wann es abläuft (nach 30 Tagen, 90 Tagen, einem Jahr oder nie). Ankreuzen kannst du nur Rechte, die du hast; die
Verwaltungsrechte sind markiert und stecken in keiner Vorgabe. `satellite.execute` steht nie auf dem Token eines Kontos.

Das Token wird **einmal** gezeigt, direkt nach dem Erzeugen, mit einem Knopf zum Kopieren und den drei Schritten, die
ein Skript braucht: die Basis-URL `https://<deine Installation>/api/v1/`, die Kopfzeile `Authorization: Bearer <Token>`
und ein erster Aufruf von `GET /api/v1/me`, der den Besitzer des Tokens nennt und was das Token jetzt darf. Danach
speichert Tesserarr nur noch seinen SHA-256-Hash: Das Token steht weder in der Seite noch in der Sitzung, im Log oder im
Protokoll. Jedes Token beginnt mit `tsr_`, damit Geheimnis-Scanner eines erkennen, das an falscher Stelle eingefügt wurde.

Die Liste zeigt je Token seine Rechte, die letzte Nutzung und den Ablauf. Beim Widerrufen fragt Tesserarr noch einmal
nach; das Token funktioniert ab der nächsten Anfrage nicht mehr. Mit `tokens.manage_all` zeigt die Seite die Token aller
Konten mit ihrem Besitzer, und du kannst jedes davon widerrufen; erzeugen kannst du Token weiterhin nur für dich.

Eigene Token ansehen und widerrufen braucht kein Recht: Das geht auch, nachdem dir `tokens.manage_own` entzogen wurde.
Auf dem Token-Pfad darf ein Token 300 Anfragen je Minute stellen und eine Quelladresse 600; darüber hinaus lautet die
Antwort 429.

Ein Token funktioniert nicht mehr, wenn es widerrufen ist, wenn es abgelaufen ist und solange sein Besitzer gesperrt ist.
Erzeugen, abgelehntes Erzeugen und Widerrufen stehen im Protokoll, mit Kennung, Name und Rechten des Tokens, nie mit dem
Token selbst.

### Auf der Kommandozeile

```bash
php artisan tesserarr:token:issue alice --name="Sonarr" --preset=arr --expires=90
php artisan tesserarr:token:issue alice --name="Script" --permission=catalog.browse --permission=status.view --expires=never
php artisan tesserarr:token:revoke 42
```

`tesserarr:token:issue` erzeugt ein Token für das genannte Konto, begrenzt auf das, was dieses Konto hat, und gibt es
einmal auf einer eigenen Zeile aus. `tesserarr:token:revoke` nimmt die Kennung des Tokens, die Zahl vor dem Strich.
Beides steht mit der Konsole als Herkunft im Protokoll.
