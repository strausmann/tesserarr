<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Ui;

use App\Models\User;

/**
 * Which navigation the app frame shows: the friend's (catalogue, tokens,
 * account) or the operator's (status, media, operations, administration).
 * See resources/js/lib/navigation.ts.
 *
 * Until the permission catalogue and the Authorizer exist (task 8), every
 * signed-in user gets the operator navigation; task 8 decides it from the
 * permissions through the Authorizer. A guest has none.
 */
enum Audience: string
{
    case Guest = 'guest';
    case Friend = 'friend';
    case Operator = 'operator';

    public static function of(?User $user): self
    {
        return $user === null ? self::Guest : self::Operator;
    }
}
