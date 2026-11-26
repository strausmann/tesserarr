<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

/**
 * The emergency override of a setting: an environment variable. The variables
 * are read into configuration when the application boots
 * (config/tesserarr.php, setting_env) so that cached configuration keeps
 * them; this class reads them from there, each time, and keeps nothing.
 * An empty variable is no variable: a compose file that passes
 * `${NAME:-}` sets nothing.
 */
final class EnvOverride
{
    public function raw(SettingKey $key): ?string
    {
        $value = config('tesserarr.setting_env.'.$key->env());

        if (! is_string($value) && ! is_int($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }
}
