<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

/**
 * The ways to sign in (Spec 9.2). The value is what identities.method stores.
 * Password and passkey belong to the account itself; the other three bring
 * an identity of their own (IdentityClaim).
 */
enum LoginMethod: string
{
    case Password = 'password';
    case Passkey = 'passkey';
    case Oidc = 'oidc';
    case Plex = 'plex';
    case Header = 'header';
}
