<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Console\Support\TerminalText;
use App\Identity\Rules\Username;
use App\Models\User;
use Illuminate\Support\Facades\Validator;

/**
 * Commands that act on one account find it by username only, never by
 * address (Spec 16.16), and name it as "display name (username) (id N)"
 * with terminal-safe text.
 */
trait FindsAccounts
{
    private function findAccount(string $username): ?User
    {
        $username = Username::normalise($username);
        $validator = Validator::make(['username' => $username], ['username' => [new Username]]);

        if ($validator->fails()) {
            $this->error((string) $validator->errors()->first('username'));

            return null;
        }

        $user = User::query()->where('username', $username)->first();
        if ($user === null) {
            $this->error(sprintf('No account with the username "%s".', TerminalText::safe($username)));
        }

        return $user;
    }

    private function describe(User $user): string
    {
        $name = TerminalText::safe($user->display_name);
        $handle = TerminalText::safe($user->username);

        return $name !== '' && $name !== $handle
            ? sprintf('%s, %s (id %d)', $name, $handle, $user->id)
            : sprintf('%s (id %d)', $handle, $user->id);
    }
}
