<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of OnlyEmailConfirmationWritesAccountEmailRule: the allowed writer
// is configured by name in the test. Never loaded.

namespace App\Fixtures\Privacy;

use App\Models\User;
use Illuminate\Support\Facades\DB;

final class AllowedWriter
{
    public function confirm(User $user, string $email): void
    {
        $user->email = $email;
        DB::table('account_email_claims')->insert(['bidx' => 'x', 'user_id' => 1, 'kind' => 'confirmed']);
    }
}
