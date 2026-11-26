<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Audit\Actor;
use App\Console\Commands\Concerns\FindsSettings;
use App\Console\Support\TerminalText;
use App\Settings\SettingsRegistry;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

final class SettingsSet extends Command
{
    use FindsSettings;

    protected $signature = 'tesserarr:settings:set
        {key : a general setting such as ui.page_size}
        {value : the new value}';

    protected $description = 'Change a general setting without the interface; logged with the console as origin';

    public function handle(SettingsRegistry $settings): int
    {
        $key = $this->findSetting((string) $this->argument('key'));
        if ($key === null) {
            return self::FAILURE;
        }

        try {
            $settings->update([$key->field() => (string) $this->argument('value')], Actor::console());
        } catch (ValidationException $invalid) {
            // The same words as the page: the field name, then why.
            foreach ($invalid->errors() as $field => $messages) {
                $this->error(sprintf('%s: %s', $field, TerminalText::safe((string) $messages[0])));
            }

            return self::FAILURE;
        }

        $this->info(sprintf('%s = %s', $key->value, TerminalText::safe((string) $settings->value($key))));

        return self::SUCCESS;
    }
}
