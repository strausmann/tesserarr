<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of NoUserLookupByEmailRule: analysed by PHPStan in the rule tests,
// never loaded.

namespace App\Fixtures\Authorization;

use App\Identity\Identity;
use App\Models\User;

final class EmailLookups
{
    public function lookups(string $email): void
    {
        User::query()->where('email', $email)->first();
        User::where('email', $email)->first();
        User::query()->firstWhere('email', $email);
        User::query()->where(['email' => $email])->first();
        User::firstOrCreate(['email' => $email]);
        User::query()->whereEmail($email)->first();
        User::query()->orWhere('users.email', $email)->first();
    }

    public function fine(string $username, string $email): void
    {
        User::query()->where('username', $username)->first();
        User::query()->whereNotNull('email')->get();
        Identity::query()->where('email', $email)->first();
    }
}
