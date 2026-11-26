<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Updates\ReleaseCheck;
use Illuminate\Console\Command;

/**
 * Asks GitHub whether a newer release of Tesserarr exists and stores the
 * answer for the "About" dialog (App\Updates\ReleaseCheck). Scheduled every
 * six hours. A failed check is not an error: the last known release stays.
 */
final class ReleaseCheckCommand extends Command
{
    protected $signature = 'tesserarr:release-check';

    protected $description = 'Look up the newest release of Tesserarr on GitHub and remember it';

    public function handle(ReleaseCheck $check): int
    {
        if (! $check->enabled()) {
            $this->info('The release check is switched off (TESSERARR_RELEASE_CHECK=false).');

            return self::SUCCESS;
        }

        $version = $check->refresh();

        if ($version === null) {
            $this->warn('No answer from GitHub; the last known release stays.');

            return self::SUCCESS;
        }

        $this->info("Newest release: {$version}");

        return self::SUCCESS;
    }
}
