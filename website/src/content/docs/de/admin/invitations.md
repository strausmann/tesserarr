---
title: Einladungen
description: Freunde einladen, eine Einladung widerrufen und den Zugang zu einem bestehenden Konto wiederherstellen.
---

Jedes Konto beginnt mit einer Einladung (Entscheidung 0004). Du erzeugst sie in der Weboberfläche, mit `artisan` oder über
die API und gibst den Link weiter, wie du willst. Der Versand per Mail aus Tesserarr kommt mit der Mail-Einrichtung.

- Eine Einladung gilt **einmal**, für begrenzte Zeit (standardmäßig sieben Tage, allgemeine Einstellung „Gültigkeit
  neuer Einladungen“), und lässt sich widerrufen, bis sie eingelöst ist.
- Gespeichert wird nur ihr Hash; eine abgeflossene Datenbank verrät also keinen brauchbaren Link. Der Link wird einmal
  angezeigt, wenn er entsteht.
- Das Token steht hinter dem `#` des Links (`/invite#…`). Browser senden diesen Teil nie mit; er landet also in keinem
  Zugriffsprotokoll, keinem Referrer und keinem Verlaufseintrag des Servers.
- Lösen zwei Menschen denselben Link gleichzeitig ein, gelingt es genau einem. Löst dieselbe Person zwei Links
  gleichzeitig ein, entsteht ein Konto, und der zweite Link bleibt unbenutzt.
- Eine **Wiederherstellungs-Einladung** bindet einen neuen Anmeldeweg an ein **bestehendes** Konto. Rolle, Freigaben,
  Kontingente, das Protokoll und die Syncthing-Kopplung bleiben. Nutze sie, wenn jemand jeden Passkey und Faktor verloren
  hat.

## In der Weboberfläche

Unter **Nutzer** listet der Abschnitt „Offene Einladungen“ jede Einladung, die noch niemand eingelöst hat, mit Notiz,
Gültigkeit und wer sie erstellt hat. **Einladen** fragt nach einer Notiz (zum Wiedererkennen) und der Gültigkeit (ein,
sieben oder 30 Tage) und zeigt den Link dann einmal an, mit einem Knopf zum Kopieren. Öffne eine Einladung, um ihre
Angaben zu sehen oder sie zu widerrufen.

Eine Wiederherstellungs-Einladung entsteht auf der Detailseite des Kontos unter „Anmeldewege“. Sie braucht die Rechte
„Einladungen erzeugen“ und „Nutzer verwalten“, und nur wer jedes Recht dieses Kontos hält, darf sie ausstellen: Der Link
ist ein Weg hinein.

## Mit artisan

```sh
php artisan tesserarr:invite [--days=7] [--note="Für Sam"]
php artisan tesserarr:invite:revoke 12
php artisan tesserarr:invite:recovery sam [--days=7]
```

`tesserarr:invite` ist auch der Weg zur ersten Einladung einer frischen Instanz; es gibt keinen Endpunkt, der ohne Konto
eine erzeugt. Jeder Befehl gibt die Nummer der Einladung und ihren Link aus.

## Über die API

`POST /api/v1/invitations` mit einem Token, das `invitations.create` trägt (die Token-Vorgabe „Einladungen erzeugen“),
erzeugt eine Einladung und antwortet mit `201` und `id`, `url` und `expires_at`. Freiwillige Felder: `days` (1 bis 90)
und `note`. `DELETE /api/v1/invitations/{id}` mit `invitations.revoke` widerruft eine. Die API-Referenz führt beide.

## Im Protokoll

Jede erstellte, widerrufene und eingelöste Einladung steht im Protokoll. Eine Einlösung nennt das Konto, die Identität,
den Anmeldeweg, die Zeit und die Quelladresse. Ein Link, der sich nicht verwenden lässt (unbekannt, eingelöst,
widerrufen, abgelaufen), bekommt für jeden Grund dieselbe Antwort; der Grund steht nur im Protokoll.

**Stand:** Einladungen kommen mit 0.1.0. Die Anmeldewege, die sie einlösen (Passwort und Passkey, OIDC, Plex, die Kopfzeile
eines Forward-Auth-Proxys), werden in derselben Version nach und nach eingeschaltet.
