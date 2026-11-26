<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Console\Commands\Concerns\FindsAccounts;
use Illuminate\Console\Command;

final class UserUnlock extends Command
{
    use FindsAccounts;

    protected $signature = 'tesserarr:user:unlock
        {username : the account, by username (never an e-mail address)}';

    protected $description = 'Unlock a locked account';

    public function handle(AuditLogger $audit): int
    {
        $user = $this->findAccount((string) $this->argument('username'));
        if ($user === null) {
            return self::FAILURE;
        }

        $actor = Actor::console();

        // Unlocking never lowers the number of active operators: no guard needed.
        $user->forceFill(['locked_at' => null])->save();

        $audit->record(AuditAction::UserUnlocked, $actor, $user);
        $this->info(sprintf('Account %s unlocked.', $this->describe($user)));

        return self::SUCCESS;
    }
}
