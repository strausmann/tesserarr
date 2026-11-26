<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

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
    | (pg_dump, custom format, mode 0600; see backups.keep below). In the
    | container this is a bind mount; see CONTRIBUTING.md.
    */

    'backup' => [
        'dir' => env('TESSERARR_BACKUP_DIR', '/var/lib/tesserarr/backups'),
        'pg_dump' => env('TESSERARR_PG_DUMP', 'pg_dump'),
    ],

    /*
    | The emergency override of the general settings (task 8b): one environment
    | variable per setting, named in App\Settings\SettingKey::env(). A variable
    | that is set wins over what the operator stored and locks the field in the
    | interface; empty means not set. Read here, once, so cached configuration
    | keeps the values (the variable names must match SettingKey::env(); a test
    | checks it). backups.keep is the number of backups tesserarr:migrate keeps
    | (1 to 50).
    */

    'setting_env' => [
        'TESSERARR_DISPLAY_NAME' => env('TESSERARR_DISPLAY_NAME'),
        'TESSERARR_DEFAULT_LOCALE' => env('TESSERARR_DEFAULT_LOCALE'),
        'TZ' => env('TZ'),
        'TESSERARR_PAGE_SIZE' => env('TESSERARR_PAGE_SIZE'),
        'TESSERARR_THEME_DEFAULT' => env('TESSERARR_THEME_DEFAULT'),
        'TESSERARR_INVITATION_DAYS' => env('TESSERARR_INVITATION_DAYS'),
        'TESSERARR_AUDIT_RETENTION_DAYS' => env('TESSERARR_AUDIT_RETENTION_DAYS'),
        'TESSERARR_BACKUP_KEEP' => env('TESSERARR_BACKUP_KEEP'),
    ],

    /*
    | Whether a newer release of Tesserarr has been published. Off unless
    | TESSERARR_RELEASE_CHECK=true: then the scheduler of the central instance
    | asks GitHub every six hours (artisan tesserarr:release-check) with
    | nothing but the User-Agent "Tesserarr", and keeps the last answer; pages
    | only read it, and the operator sees it under "About". Off, nothing is
    | sent. The timeout is in seconds.
    */

    'release_check' => [
        'enabled' => (bool) env('TESSERARR_RELEASE_CHECK', false),
        'url' => env('TESSERARR_RELEASE_CHECK_URL', 'https://api.github.com/repos/strausmann/tesserarr/releases/latest'),
        'timeout' => (int) env('TESSERARR_RELEASE_CHECK_TIMEOUT', 5),
        'releases' => 'https://github.com/strausmann/tesserarr/releases/tag/',
    ],

];
