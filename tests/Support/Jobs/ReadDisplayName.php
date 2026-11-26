<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Jobs;

use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Cache;

/** Writes the display name it reads into the cache under "seen.<n>" (a queued job of the settings tests). */
final class ReadDisplayName implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function __construct(private readonly int $number) {}

    public function handle(SettingsRegistry $settings): void
    {
        Cache::put("seen.{$this->number}", $settings->value(SettingKey::DisplayName));
    }
}
