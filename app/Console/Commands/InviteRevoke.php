<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Support\TerminalText;
use App\Invitations\InvitationNotRevocable;
use App\Invitations\InvitationService;
use Illuminate\Console\Command;

final class InviteRevoke extends Command
{
    protected $signature = 'tesserarr:invite:revoke
        {id : the id of the invitation (printed when it was issued)}';

    protected $description = 'Revoke an invitation that nobody has redeemed yet';

    public function handle(InvitationService $invitations): int
    {
        $id = (string) $this->argument('id');

        try {
            if (preg_match('/\A[1-9]\d{0,18}\z/', $id) !== 1) {
                throw new InvitationNotRevocable;
            }
            $invitations->revoke((int) $id, Actor::console());
        } catch (InvitationNotRevocable) {
            $this->error(sprintf('No open invitation with the id "%s".', TerminalText::safe($id)));

            return self::FAILURE;
        }

        $this->info(sprintf('Invitation %s revoked: its link no longer works.', $id));

        return self::SUCCESS;
    }
}
