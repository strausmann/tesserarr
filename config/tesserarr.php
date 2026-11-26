<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Env;

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
    | checks it). The raw text is read, not env(): env() turns the words
    | true, false and null into booleans and null, which would let an invalid
    | override look like no override. backups.keep is the number of backups tesserarr:migrate keeps
    | (1 to 50).
    */

    'setting_env' => [
        'TESSERARR_DISPLAY_NAME' => Env::getRepository()->get('TESSERARR_DISPLAY_NAME'),
        'TESSERARR_DEFAULT_LOCALE' => Env::getRepository()->get('TESSERARR_DEFAULT_LOCALE'),
        'TZ' => Env::getRepository()->get('TZ'),
        'TESSERARR_PAGE_SIZE' => Env::getRepository()->get('TESSERARR_PAGE_SIZE'),
        'TESSERARR_THEME_DEFAULT' => Env::getRepository()->get('TESSERARR_THEME_DEFAULT'),
        'TESSERARR_INVITATION_DAYS' => Env::getRepository()->get('TESSERARR_INVITATION_DAYS'),
        'TESSERARR_AUDIT_RETENTION_DAYS' => Env::getRepository()->get('TESSERARR_AUDIT_RETENTION_DAYS'),
        'TESSERARR_BACKUP_KEEP' => Env::getRepository()->get('TESSERARR_BACKUP_KEEP'),
    ],

    /*
    | The keys for personal data (task 8a, App\Privacy\PiiKeys): the e-mail
    | addresses of accounts and identities and the PGP keys are encrypted with
    | TESSERARR_PII_KEY (32 bytes, raw or "base64:…", never APP_KEY) and found
    | through an HMAC keyed with TESSERARR_BLIND_INDEX_KEY. The previous keys
    | serve `tesserarr:pii:rotate`. From the environment only (Infisical in
    | production), never from the database; the central instance does not
    | start without them.
    */

    'pii' => [
        'key' => Env::getRepository()->get('TESSERARR_PII_KEY'),
        'previous_keys' => Env::getRepository()->get('TESSERARR_PII_PREVIOUS_KEYS'),
        'blind_index_key' => Env::getRepository()->get('TESSERARR_BLIND_INDEX_KEY'),
        'blind_index_previous_key' => Env::getRepository()->get('TESSERARR_BLIND_INDEX_PREVIOUS_KEY'),
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
