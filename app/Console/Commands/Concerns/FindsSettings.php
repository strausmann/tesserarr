<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands\Concerns;

use App\Console\Support\TerminalText;
use App\Settings\SettingKey;

/** Commands on one general setting find it by its dotted key, and nothing else. */
trait FindsSettings
{
    private function findSetting(string $name): ?SettingKey
    {
        $key = SettingKey::tryFrom($name);
        if ($key === null) {
            $this->error(sprintf(
                'No general setting "%s". The settings are: %s.',
                TerminalText::safe($name),
                implode(', ', array_map(fn (SettingKey $known) => $known->value, SettingKey::cases())),
            ));
        }

        return $key;
    }
}
