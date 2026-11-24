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
    | Sign-in for the browser tests (/_testing/login/{role}). Only the `e2e`
    | stage of docker/Dockerfile sets it, and it also needs APP_ENV=testing;
    | see App\Http\Controllers\Testing\E2eLoginController.
    */

    'e2e_login' => filter_var(env('TESSERARR_E2E_LOGIN', false), FILTER_VALIDATE_BOOL),

];
