<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Console\Support\TerminalText;
use App\Settings\SettingKey;
use Carbon\CarbonInterval;

/**
 * The --days option of the invitation commands: empty for the instance
 * default, otherwise a whole number of days from 1 to the maximum of the
 * general settings.
 */
trait ReadsInvitationDays
{
    /** @return array{0: bool, 1: CarbonInterval|null} whether it is valid, and the interval (null: the default) */
    private function invitationDays(): array
    {
        $days = $this->option('days');
        if ($days === null || $days === '') {
            return [true, null];
        }

        $value = (string) $days;
        if (preg_match('/\A[1-9]\d{0,2}\z/', $value) !== 1 || (int) $value > SettingKey::INVITATION_DAYS_MAX) {
            $this->error(sprintf('--days must be a whole number from 1 to %d, not "%s".', SettingKey::INVITATION_DAYS_MAX, TerminalText::safe($value)));

            return [false, null];
        }

        return [true, CarbonInterval::days((int) $value)];
    }
}
