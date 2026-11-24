<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

return [

    /*
    | Reverse proxies whose X-Forwarded-* headers are trusted, as a
    | comma-separated list of addresses or CIDR ranges (ADR-0009). Empty means
    | no proxy is trusted and the peer address is the client address.
    */

    'trusted_proxies' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('TESSERARR_TRUSTED_PROXIES', '')),
    ))),

    /*
    | Backups written by `artisan tesserarr:migrate` before it migrates
    | (pg_dump, custom format, mode 0600; the five newest are kept). In the
    | container this is a bind mount; see CONTRIBUTING.md.
    */

    'backup' => [
        'dir' => env('TESSERARR_BACKUP_DIR', '/var/lib/tesserarr/backups'),
        'pg_dump' => env('TESSERARR_PG_DUMP', 'pg_dump'),
    ],

];
