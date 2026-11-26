<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http;

/**
 * Request fields that are never flashed into the session after a failed
 * validation (bootstrap/app.php): secrets, addresses and the PGP key, and
 * the token of a confirmation link. A form that shows such a field again
 * after an error keeps the value in the browser instead.
 */
final class PersonalInput
{
    public const FIELDS = [
        'current_password',
        'password',
        'password_confirmation',
        'email',
        'pending_email',
        'pgp_public_key',
        'login',
        'token',
    ];
}
