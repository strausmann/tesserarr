<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
| Helpers of the general settings tests (tests/Feature/Settings). Loaded by
| tests/Pest.php.
*/

namespace Tests\Support;

use App\Settings\SettingKey;

/** The wire name of a setting in the form: its key with dots as underscores. */
function settingField(SettingKey $key): string
{
    return str_replace('.', '_', $key->value);
}

/**
 * The whole form as the page sends it: every general setting with a value that
 * differs from its default.
 *
 * @param  array<string, mixed>  $override
 * @return array<string, mixed>
 */
function generalForm(array $override = []): array
{
    return [
        'instance_display_name' => 'Tesserarr',
        'locale_default' => 'de',
        'time_zone' => 'Europe/Berlin',
        'ui_page_size' => 100,
        'ui_theme_default' => 'dark',
        'invitations_default_days' => '14',
        'audit_retention_days' => '90',
        'backups_keep' => '7',
        ...$override,
    ];
}

/** Sets what the environment variable of a setting holds, as the configuration reads it at boot. */
function overrideWith(string $name, ?string $value): void
{
    config(["tesserarr.setting_env.{$name}" => $value]);
}
