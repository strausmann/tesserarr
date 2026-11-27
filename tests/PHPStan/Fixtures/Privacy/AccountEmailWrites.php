<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture of OnlyEmailConfirmationWritesAccountEmailRule: analysed by PHPStan
// in the rule tests, never loaded.

namespace App\Fixtures\Privacy;

use App\Identity\Identity;
use App\Models\User;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

final class AccountEmailWrites
{
    public function writes(User $user, string $email, ConnectionInterface $db): void
    {
        $user->email = $email;
        $user->pending_email_bidx ??= 'x';
        $user->fill(['email' => $email]);
        $user->forceFill(['email_confirmed_at' => now()]);
        $user->update(['display_name' => 'A', 'email' => $email]);
        User::create(['username' => 'a', 'email' => $email]);
        User::query()->where('id', 1)->update(['email_bidx' => 'x']);
        User::updateOrCreate(['username' => 'a'], ['pending_email' => $email]);
        DB::table('users')->where('id', 1)->update(['email' => $email]);
        $db->table('users')->insert([['username' => 'a', 'email' => $email]]);
        DB::table('account_email_claims')->insert(['bidx' => 'x', 'user_id' => 1, 'kind' => 'pending']);
        $db->table('account_email_claims')->where('user_id', 1)->delete();
        $user->setAttribute('email', $email);
    }

    public function fine(User $user, Identity $identity, string $email): void
    {
        $user->display_name = 'A';
        $user->forceFill(['locked_at' => now()])->save();
        $identity->email = $email;
        $identity->update(['email' => $email]);
        Identity::query()->create(['user_id' => 1, 'method' => 'oidc', 'provider_key' => 'x', 'subject' => 's', 'email' => $email]);
        DB::table('users')->where('id', 1)->update(['locked_at' => now()]);
        DB::table('account_email_claims')->where('user_id', 1)->first();
        $read = $user->email;
    }
}
