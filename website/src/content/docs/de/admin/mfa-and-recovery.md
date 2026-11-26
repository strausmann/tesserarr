---
title: MFA und Wiederherstellung
description: Einen zweiten Faktor verlangen und jemanden wieder hereinlassen, der seinen verloren hat.
---

- Du kannst einen zweiten Faktor für alle zur Pflicht machen. **Betreiberrechte verlangen ihn immer.**
- Ein Passkey mit Benutzerbestätigung genügt allein; ein Passwort-Konto braucht zusätzlich einen Passkey oder TOTP.
- Zehn Wiederherstellungscodes je zweitem Faktor, als Hash gespeichert, einmal angezeigt.
- Der letzte Ausweg: Du entfernst den verlorenen Passkey oder Faktor und stellst eine
  [Wiederherstellungs-Einladung](/tesserarr/de/admin/invitations/) aus.
- Jede Wiederherstellung landet im [Protokoll](/tesserarr/de/admin/audit-log/).

**Stand:** MFA und Wiederherstellung kommen mit 0.1.0.
