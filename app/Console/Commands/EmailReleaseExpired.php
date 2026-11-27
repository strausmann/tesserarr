<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Privacy\EmailConfirmation;
use Illuminate\Console\Command;

/**
 * Frees account addresses whose confirmation was not opened within
 * EmailConfirmation::RESERVATION_HOURS. The scheduler runs it every hour.
 */
final class EmailReleaseExpired extends Command
{
    protected $signature = 'tesserarr:email:release-expired';

    protected $description = 'Free account addresses whose 24-hour reservation ran out without confirmation';

    public function handle(EmailConfirmation $confirmation): int
    {
        $this->line(sprintf('%d reservation(s) released', $confirmation->releaseExpired()));

        return self::SUCCESS;
    }
}
