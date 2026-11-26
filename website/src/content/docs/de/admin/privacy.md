---
title: Personendaten
description: Was Tesserarr verschlüsselt, wie Adressen gefunden und bestätigt werden, der Schlüsselwechsel und wo der Schutz endet.
---

Tesserarr speichert so wenig Personendaten wie möglich, und was es speichert, liegt verschlüsselt in der Datenbank.

## Was verschlüsselt ist

| Daten | Wo | Wie |
| --- | --- | --- |
| Konto-Adresse (bestätigt, und die, die auf ihren Link wartet) | `users.email`, `users.pending_email` | verschlüsselt mit `TESSERARR_PII_KEY` (AES-256-GCM) |
| Adresse, die ein Anmeldeanbieter gemeldet hat | `identities.email` | genauso verschlüsselt |
| PGP-Schlüssel für verschlüsselte Mails | `users.pgp_public_key` | genauso verschlüsselt |
| Mails in der Warteschlange (der Bestätigungslink, später Einladungen) | `jobs`, `failed_jobs` | der Auftrag ist mit `APP_KEY` verschlüsselt; Aufträge leben kurz |

Eine Adresse wird über einen Blindindex gefunden: einen HMAC-SHA256 der Adresse mit einem eigenen Schlüssel,
`TESSERARR_BLIND_INDEX_KEY`. Die Adresse wird vorher vereinheitlicht (Leerzeichen außen entfernt, Unicode NFC,
Kleinbuchstaben, die Domain in ihrer ASCII-Form), damit `Alice@Example.org` und `alice@example.org` eine Adresse sind.
Ohne den Schlüssel verrät der Index nichts.

Beide Schlüssel kommen nur aus der Umgebung (siehe [Konfiguration](/tesserarr/de/install/configuration/)). Sie stehen
nie in der Datenbank, in einem Protokoll oder im Browser. Die Zentrale startet ohne sie nicht und lehnt einen
`TESSERARR_PII_KEY` ab, der gleich `APP_KEY` ist.

## Adressen: freiwillig, eindeutig, bestätigt

- Ein Konto braucht keine Adresse; es meldet sich mit seinem Benutzernamen an.
- Eine Adresse gilt erst, wenn ihr Inhaber den Link geöffnet hat, der an sie geht. Der Link funktioniert einmal und
  60 Minuten lang. Bis dahin ist die Adresse 24 Stunden reserviert; danach kann sie jeder wieder eintragen.
- Eine Adresse gehört zu einem Konto. Wer eine Adresse einträgt, die ein anderes Konto hat oder reserviert, bekommt
  „Diese Adresse ist bereits einem anderen Konto zugeordnet“, ohne dass das andere Konto genannt wird. Ein Nutzer kann
  zehn Adressen pro Stunde prüfen; jede Ablehnung steht im Protokoll.
- Das Token des Links steht in seinem Fragment (`/email/confirm#…`), das der Browser nie mitschickt: Es landet in keinem
  Zugriffsprotokoll, Referer und keiner Sitzung. Der Link entsteht aus `APP_URL`, nie aus der Adresse, über die eine Anfrage kam, und die Instanz antwortet
  nur auf den Host von `APP_URL` (und `TESSERARR_TRUSTED_HOSTS`).
- Wird eine bestätigte Adresse geändert, bleibt die alte gültig, bis die neue bestätigt ist, und die alte Adresse
  erfährt von der Änderung.
- Adressen lassen sich nur eintragen, solange der Betreiber sie erlaubt (Einstellung `email.allowed`) und Mails
  verschickt werden können. Mit einem Mailer, der nur ins Protokoll schreibt, lässt sich nichts bestätigen.
- Die Adresse, die ein Anbieter meldet, bleibt bei diesem Anmeldeweg. Sie ist nicht eindeutig, und keine Anmeldung
  ändert je die Konto-Adresse. Hat ein Konto keine Adresse, bietet *Mein Konto* die Adresse des Anbieters zur Übernahme an.
- Die eigene bestätigte Adresse zu ändern braucht eine Bestätigung mit dem zweiten Faktor, auf jedem Weg (auch ein
  Betreiber auf seiner eigenen Nutzerseite); das kommt mit einer späteren Version.
- Ein Betreiber trägt auf der Seite eines Nutzers eine Adresse für ihn ein; sie gilt, sobald ihr Inhaber sie bestätigt.
  Dort lässt sich eine Reservierung auch sofort freigeben.

## Wer eine Adresse sieht

- Jeder sieht seine eigenen Adressen und seine eigene Aktivität unter *Mein Konto*.
- Die Konto-Adressen anderer sehen nur Inhaber von `users.view`.
- Das Protokoll speichert Kennungen, nie Namen oder Adressen; die Namen werden beim Anzeigen nachgeschlagen.
- Jeder Protokollkanal ersetzt Adressen durch `[email]`, auch in den Meldungen und Aufrufketten von Fehlern. Das
  Zugriffsprotokoll schreibt das Token eines Bestätigungslinks als `[redacted]`.

## Schlüssel wechseln

1. Den alten Schlüssel in `TESSERARR_PII_PREVIOUS_KEYS` eintragen (durch Komma getrennt, mehrere sind möglich) und den
   neuen in `TESSERARR_PII_KEY`. Für den Blindindex den alten Schlüssel in `TESSERARR_BLIND_INDEX_PREVIOUS_KEY` und den
   neuen in `TESSERARR_BLIND_INDEX_KEY`.
2. Neu starten. Alles bleibt lesbar: Werte, die mit einem alten Schlüssel geschrieben wurden, werden weiter entschlüsselt,
   und eine Adresse wird unter dem alten Blindindex-Schlüssel weiter gefunden und bleibt eindeutig, bis die Rotation fertig ist.
3. Erst zählen, dann umschreiben:

   ```bash
   php artisan tesserarr:pii:rotate --dry-run
   php artisan tesserarr:pii:rotate
   ```

   Der Befehl schreibt jeden Wert mit dem aktuellen Schlüssel neu und berechnet den Blindindex neu, wenn ein alter
   Blindindex-Schlüssel gesetzt ist. Er arbeitet in Stapeln und lässt sich erneut starten. Er gibt nur Anzahlen aus.
4. Die alten Schlüssel entfernen und neu starten.

## Wo der Schutz endet

Die Verschlüsselung schützt vor einem gestohlenen Datenbankabzug, einer Sicherung und einem weitergegebenen Protokoll.
Sie schützt nicht vor jemandem, der die laufende Anwendung übernommen hat: Sie hält die Schlüssel, solange sie läuft.
