<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\ReadsInvitationDays;
use App\Invitations\InvitationService;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Issues an access invitation from the console and prints its link. The link
 * is shown once and is the only copy; the operator passes it on in a way
 * they trust. Sending it by mail arrives with task 10a.
 */
final class Invite extends Command
{
    use ReadsInvitationDays;

    protected $signature = 'tesserarr:invite
        {--days= : how many days the link stays valid (default: the general setting, 7)}
        {--note= : a label to recognise the invitation by, such as a name (at most 100 characters)}';

    protected $description = 'Invite somebody: prints a link that makes one new account';

    public function handle(InvitationService $invitations): int
    {
        [$valid, $ttl] = $this->invitationDays();
        if (! $valid) {
            return self::FAILURE;
        }

        $note = $this->option('note');

        try {
            $issued = $invitations->issue(Actor::console(), ttl: $ttl, note: is_string($note) ? $note : null);
        } catch (InvalidArgumentException) {
            $this->error('--note takes at most 100 characters and no control characters.');

            return self::FAILURE;
        }

        $this->info(sprintf('Invitation %d, valid until %s. It can be redeemed once:', $issued->invitation->id, $issued->invitation->expires_at->toIso8601String()));
        $this->line($issued->url);
        $this->comment('The link is shown only now. Pass it on in a way you trust.');

        return self::SUCCESS;
    }
}
