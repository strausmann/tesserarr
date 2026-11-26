<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

/** A setting as it is in force: the value, where it comes from, and its environment variable. */
final readonly class ResolvedSetting
{
    public function __construct(
        public SettingKey $key,
        public string|int $value,
        public SettingSource $source,
        /** The environment variable holds something this setting does not accept: it is ignored. */
        public bool $envInvalid = false,
    ) {}

    public function overridden(): bool
    {
        return $this->source === SettingSource::Environment;
    }
}
