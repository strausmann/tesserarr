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
        /**
         * The environment variable holds something this setting does not accept.
         * Normally it is ignored and the stored value or the default is in force;
         * for backups.keep tesserarr:migrate refuses to run (envRefusesMigrate()).
         */
        public bool $envInvalid = false,
        /** The stored value does not pass the rules (an old or hand-edited row): it is not in force. */
        public bool $storedInvalid = false,
    ) {}

    /**
     * The invalid environment value is not merely ignored: tesserarr:migrate
     * reads TESSERARR_BACKUP_KEEP strictly and refuses to run on it.
     */
    public function envRefusesMigrate(): bool
    {
        return $this->envInvalid && $this->key === SettingKey::BackupsKeep;
    }

    public function overridden(): bool
    {
        return $this->source === SettingSource::Environment;
    }
}
