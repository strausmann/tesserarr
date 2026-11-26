<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Console\Commands\Concerns\FindsSettings;
use App\Console\Support\TerminalText;
use App\Settings\ResolvedSetting;
use App\Settings\SettingSource;
use App\Settings\SettingsRegistry;
use Illuminate\Console\Command;

final class SettingsGet extends Command
{
    use FindsSettings;

    protected $signature = 'tesserarr:settings:get
        {key? : a general setting such as ui.page_size; without it, all of them}';

    protected $description = 'Show a general setting, its value in force and where the value comes from';

    public function handle(SettingsRegistry $settings): int
    {
        $name = $this->argument('key');
        if (is_string($name)) {
            $key = $this->findSetting($name);

            return $key === null ? self::FAILURE : $this->show($settings->resolve($key));
        }

        foreach ($settings->resolveAll() as $setting) {
            $this->show($setting);
        }

        return self::SUCCESS;
    }

    private function show(ResolvedSetting $setting): int
    {
        $source = match ($setting->source) {
            SettingSource::Environment => 'environment: '.$setting->key->env(),
            default => $setting->source->value,
        };
        $this->line(sprintf('%s = %s (%s)', $setting->key->value, TerminalText::safe((string) $setting->value), $source));
        if ($setting->envInvalid) {
            $this->warn(sprintf('%s holds a value this setting does not accept; it is ignored.', $setting->key->env()));
        }

        return self::SUCCESS;
    }
}
