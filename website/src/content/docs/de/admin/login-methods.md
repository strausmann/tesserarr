---
title: Anmeldewege
description: Welche Anmeldewege es gibt, wie sie an Konten binden und wie weit einem vorgelagerten zweiten Faktor vertraut wird.
---

Du schaltest jeden Weg ein oder aus: Passkey, Benutzername und Passwort, OpenID Connect (mehrere Anbieter gleichzeitig),
Plex und die Kopfzeilen-Anmeldung eines Forward-Auth-Proxys (Authelia, oauth2-proxy und ähnliche, Entscheidung 0010).
Jeder Weg meldet ein bestehendes Konto an; keiner legt eines an (Entscheidung 0004).

**Zweiter Faktor eines vorgelagerten Anbieters.** Je Anmeldeweg wählst du (Entscheidung 0015):

| Einstellung | Für |
| --- | --- |
| Eigenen zweiten Faktor von Tesserarr verlangen | jeden Weg |
| Der vorgelagerten Anmeldung vertrauen | jeden Weg |
| Vertrauen, wenn belegt (`amr` im ID-Token dieser Anmeldung) | OpenID Connect |

„Wenn belegt“ liest nur den Claim `amr` des aktuellen ID-Tokens und prüft `auth_time` gegen `max_age`; fehlt der Claim,
gilt es als nicht belegt.

**Stand:** Die Anmeldewege kommen mit 0.1.0.
