---
title: Anmelden
description: Eine Einladung einlösen und sich mit Passkey, Passwort oder einem externen Anbieter anmelden.
---

Eine Einladung ist ein Link, der einmal und für begrenzte Zeit gilt (sieben Tage, wenn der Betreiber nichts anderes
gewählt hat). Sie legt dein Konto an; einen anderen Weg zu einem Konto gibt es nicht.

Der Betreiber entscheidet, welche Anmeldewege es gibt. Du kannst mehrere gleichzeitig haben, alle am selben Konto:

| Anmeldeweg | Braucht eine E-Mail-Adresse |
| --- | --- |
| Passkey | nein |
| Benutzername und Passwort | nein |
| OpenID Connect (zum Beispiel Authentik, Authelia, Keycloak, Entra ID) | die des Anbieters |
| Plex-Konto | die des Anbieters |
| Anmeldung durch einen Proxy vor Tesserarr (Forward-Auth) | nein |

Ein externer Anbieter meldet nur ein bestehendes Konto an. Ohne Einladung legt er nie eines an.

**Stand:** Die Anmeldung kommt mit 0.1.0.
