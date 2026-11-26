---
title: Audit log
description: Every administrative action, from the web interface, the API and the command line.
---

Tesserarr records every administrative action: invitations, sign-in methods, roles, tokens, grants and recoveries,
whoever triggered them and however (web interface, API or `artisan`). The log is append-only and names people by their
user ID, not by their e-mail address. Satellites write no log of their own: they report results, and the central
instance records them (decision record 0009).

## What an entry holds

| Field | Content |
| --- | --- |
| Time | When it happened |
| Who and origin | The account (shown by its current name), or the console with the operating-system user that ran `artisan`; the origin is *web interface*, *CLI* or *API* |
| Action | What was done, with its key, for example `role.update` |
| Result | *Done*, or *Refused* when a guard rule stopped it (a permission the actor lacks, the last operator) |

An entry never holds a secret: the log refuses any detail whose name looks like one (a token, a password, a key), and
the entry is not written at all. Settings are logged by their name, never by their value.

## Append-only

The database itself refuses to change or delete an entry: a trigger on the table rejects every `UPDATE` and `DELETE`.

## Reading the log

*Audit log* in the navigation (permission `audit.view`) shows ten entries per page, newest first, with *Newer* and
*Older*. Filter by period (24 hours, 7 days, 30 days), by person or the console, by action and by origin.
