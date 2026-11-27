---
title: Nutzer
description: Konten, Benutzernamen, Kontaktadressen, Rollen und das Sperren eines Kontos.
---

Jede Person, die Tesserarr nutzt, hat ein Konto. Neue Konten entstehen nur über eine
[Einladung](/tesserarr/de/admin/invitations/).

## Benutzername und Adressen

- **Benutzername.** Der Name, mit dem du dich anmeldest: 3 bis 64 Zeichen, Kleinbuchstaben, Buchstaben, Ziffern, Punkt,
  Unterstrich und Bindestrich. Ein Benutzername enthält nie `@`, damit er nicht mit einer E-Mail-Adresse verwechselt
  wird. `Max` und `max` sind derselbe Name. Jeder kann seinen eigenen Benutzernamen unter *Konto* ändern; die Änderung
  wird protokolliert.
- **Anzeigename.** Der Name, den andere sehen. Steuerzeichen werden abgelehnt.
- **Konto-Adresse.** Freiwillig. Sie gilt, sobald ihr Inhaber den Link geöffnet hat, der an sie geht, und sie ist
  eindeutig: eine Adresse, ein Konto. Sie liegt verschlüsselt in der Datenbank. Ein Betreiber kann sie auf der Seite des
  Nutzers eintragen (*Speichern und Bestätigungslink senden*); die Regeln stehen unter
  [Personendaten](/tesserarr/de/admin/privacy/).
- **Adresse beim Anbieter.** Meldet sich jemand über einen Anbieter an (OIDC, Plex), speichert Tesserarr die Adresse,
  die der Anbieter genannt hat, beim jeweiligen Anmeldeweg. Die Nutzerliste zeigt sie getrennt von der Konto-Adresse. Sie ist
  nicht eindeutig, und keine Anmeldung ändert die Konto-Adresse.

Schickt ein Anbieter einen Namen in Form einer E-Mail-Adresse (etwa den User Principal Name von Entra ID), schlägt
Tesserarr den Teil vor dem `@` als Benutzernamen vor und nummeriert ihn, wenn er vergeben ist: `bob`, `bob2`.

## Rollen

Ein Konto hat eine Rolle. Du änderst sie in der Nutzerliste oder auf der Befehlszeile:

```bash
php artisan tesserarr:role:assign bob operator
```

Der Befehl nennt das geänderte Konto, zum Beispiel `Bob, bob (id 7)`. Die Änderung gilt ab der nächsten Anfrage dieser
Person; eine neue Anmeldung ist nicht nötig. Was jede Rolle darf, steht unter
[Rollen und Token](/tesserarr/de/admin/roles-and-tokens/).

Der erste Betreiber: Sobald die erste Einladung eingelöst ist, gibst du diesem Konto mit dem Befehl oben die Rolle
`operator`.

## Ein Konto sperren

Ein gesperrtes Konto wird bei seiner nächsten Anfrage abgemeldet, kann sich nicht anmelden, und seine Token gelten
nicht mehr. Seine Daten bleiben erhalten.

```bash
php artisan tesserarr:user:lock bob
php artisan tesserarr:user:unlock bob
```

Der letzte aktive Betreiber lässt sich weder sperren noch herabstufen oder entfernen. Sperren oder herabstufen kannst du
nur ein Konto, dessen Rechte du selbst hast: Ein Konto kann nie ein Konto ändern, das mehr darf als es selbst. Das eigene
Konto lässt sich in der Weboberfläche nicht sperren.

Konten werden in der Weboberfläche nicht gelöscht; sperre ein Konto stattdessen.

Jede Änderung auf dieser Seite landet im [Protokoll](/tesserarr/de/admin/audit-log/).
