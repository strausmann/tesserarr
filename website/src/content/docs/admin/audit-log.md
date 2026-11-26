---
title: Audit log
description: Every administrative action, from the web interface, the API and the command line.
---

Tesserarr records every administrative action: invitations, sign-in methods, roles, tokens, grants and recoveries,
whoever triggered them and however (web interface, API or `artisan`). The log is append-only and names people by their
user ID, not by their e-mail address. Satellites write no log of their own: they report results, and the central
instance records them (decision record 0009).

**Status:** the audit log arrives with 0.1.0.
