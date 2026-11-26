<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\LastOperatorProtected;
use App\Authorization\RoleGuard;
use App\Console\Commands\Concerns\FindsAccounts;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class UserLock extends Command
{
    use FindsAccounts;

    protected $signature = 'tesserarr:user:lock
        {username : the account, by username (never an e-mail address)}';

    protected $description = 'Lock an account: it is signed out and cannot sign in or use its tokens; keeps at least one active operator';

    public function handle(RoleGuard $guard, AuditLogger $audit): int
    {
        $user = $this->findAccount((string) $this->argument('username'));
        if ($user === null) {
            return self::FAILURE;
        }

        $actor = Actor::console();

        try {
            DB::transaction(function () use ($guard, $audit, $actor, $user): void {
                // The account first, as on every path: the operator guard
                // counts after this lock, so a concurrent role change of this
                // account is finished (and counted) or waits.
                $guard->lockAccounts([$user]);
                $guard->assertKeepsAnOperator(fn () => $user->forceFill(['locked_at' => now()])->save());
                $audit->record(AuditAction::UserLocked, $actor, $user);
            }, 3);
        } catch (LastOperatorProtected) {
            $audit->record(AuditAction::UserLocked, $actor, $user, [], AuditOutcome::Denied);
            $this->error(sprintf('Refused: %s is the last active operator. Make another account an operator first.', $this->describe($user)));

            return self::FAILURE;
        }

        $this->info(sprintf('Account %s locked.', $this->describe($user)));

        return self::SUCCESS;
    }
}
