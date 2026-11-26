---
title: Backups and upgrades
description: The backup before every migration, and how an upgrade runs.
---

An upgrade is a new image tag. On start, the one-off `migrate` container runs `artisan tesserarr:migrate`:

1. When migrations are pending, it writes a `pg_dump` backup to `TESSERARR_BACKUP_DIR` first and keeps the newest
   `TESSERARR_BACKUP_KEEP` (five by default).
2. When the backup fails or is empty, it stops without migrating.
3. Only then it migrates. With nothing pending there is no backup.

`web`, `worker` and `scheduler` start after `migrate` has finished successfully. Migrations only go forward; to go back,
restore the backup with the image of the version that wrote it. See [configuration](/tesserarr/install/configuration/) for
the variables.

Keep the backup directory on a volume that your own backup covers: the dumps contain the whole database.

## Open tabs

A tab that is open during an upgrade keeps the interface of the old version. It notices the new version from the next
answer of the server and shows **New version available**, without reloading by itself, so nothing that is being typed is
lost. The next page a user opens loads the new version anyway. See [version and updates](/tesserarr/using/updates/).
