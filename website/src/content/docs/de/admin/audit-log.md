---
title: Protokoll
description: Jede Verwaltungsaktion, aus der Weboberfläche, über die API und von der Befehlszeile.
---

Tesserarr protokolliert jede Verwaltungsaktion: Einladungen, Anmeldewege, Rollen, Token, Freigaben und
Wiederherstellungen, egal wer sie auslöst und wie (Weboberfläche, API oder `artisan`). Das Protokoll wird nur ergänzt,
nie geändert, und nennt Menschen über ihre Nutzer-ID, nicht über ihre E-Mail-Adresse. Satelliten schreiben kein eigenes
Protokoll: Sie melden Ergebnisse, und die Zentrale protokolliert sie (Entscheidung 0009).

**Stand:** Das Protokoll kommt mit 0.1.0.
