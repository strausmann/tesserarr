<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsAccounts;
use App\Console\Commands\Concerns\ReadsInvitationDays;
use App\Invitations\InvitationKind;
use App\Invitations\InvitationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Issues a recovery invitation (Spec 9.3, last resort): the link binds a new
 * sign-in method to the existing account, which keeps its role, grants and
 * history. The account is named by its username. It is short-lived (one day,
 * three at most): it opens an existing account.
 */
final class InviteRecovery extends Command
{
    use FindsAccounts, ReadsInvitationDays;

    protected $signature = 'tesserarr:invite:recovery
        {username : the account that gets a new way in}
        {--days= : how many days the link stays valid, 1 to 3 (default: one day)}';

    protected $description = 'Issue a recovery invitation: a link that adds a sign-in method to an existing account';

    public function handle(InvitationService $invitations): int
    {
        $user = $this->findAccount((string) $this->argument('username'));
        [$valid, $ttl] = $this->invitationDays();
        if ($user === null || ! $valid) {
            return self::FAILURE;
        }

        try {
            $issued = $invitations->issue(Actor::console(), InvitationKind::Recovery, $user, $ttl);
        } catch (InvalidArgumentException) {
            $this->error(sprintf('A recovery invitation is valid for at most %d days.', intdiv(InvitationService::RECOVERY_MAX_HOURS, 24)));

            return self::FAILURE;
        }

        $this->info(sprintf('Recovery invitation %d for %s, valid until %s:', $issued->invitation->id, $this->describe($user), $issued->invitation->expires_at->toIso8601String()));
        $this->line($issued->url);
        $this->comment('Whoever opens it gets into this account. Pass it on only to its owner.');

        return self::SUCCESS;
    }
}
