<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

use App\Database\Backup\PreMigrateBackups;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The general settings of the operator (task 8b): one case per key in
 * app_settings. Each knows its default, the environment variable that may
 * override it in an emergency, the name of its field in the form and what a
 * valid value is. Anything not listed here is not a general setting: the
 * platform URL, the trusted proxies, keys, the database and the role stay
 * environment-only and have no case.
 */
enum SettingKey: string
{
    case DisplayName = 'instance.display_name';
    case DefaultLocale = 'locale.default';
    case TimeZone = 'time.zone';
    case PageSize = 'ui.page_size';
    case DefaultTheme = 'ui.theme_default';
    case InvitationDays = 'invitations.default_days';
    case AuditRetentionDays = 'audit.retention_days';
    case BackupsKeep = 'backups.keep';

    public const NAME_MAX = 60;

    public const PAGE_SIZES = [25, 50, 100];

    public const LOCALES = ['de', 'en'];

    public const THEMES = ['system', 'light', 'dark'];

    public const INVITATION_DAYS_MAX = 90;

    public const RETENTION_DAYS_MIN = 30;

    public const RETENTION_DAYS_MAX = 3650;

    /** The value the setting has until somebody sets it. */
    public function default(): string|int
    {
        return match ($this) {
            self::DisplayName => 'Tesserarr',
            self::DefaultLocale => 'en',
            self::TimeZone => 'UTC',
            self::PageSize => 50,
            self::DefaultTheme => 'system',
            self::InvitationDays => 7,
            self::AuditRetentionDays => 365,
            self::BackupsKeep => PreMigrateBackups::KEEP,
        };
    }

    /** The environment variable that overrides the setting; only for an emergency. */
    public function env(): string
    {
        return match ($this) {
            self::DisplayName => 'TESSERARR_DISPLAY_NAME',
            self::DefaultLocale => 'TESSERARR_DEFAULT_LOCALE',
            // The one name the container's own time zone already uses (Settings-General.dc.html).
            self::TimeZone => 'TZ',
            self::PageSize => 'TESSERARR_PAGE_SIZE',
            self::DefaultTheme => 'TESSERARR_THEME_DEFAULT',
            self::InvitationDays => 'TESSERARR_INVITATION_DAYS',
            self::AuditRetentionDays => 'TESSERARR_AUDIT_RETENTION_DAYS',
            self::BackupsKeep => 'TESSERARR_BACKUP_KEEP',
        };
    }

    /** The name of the field in the form and in its validation errors: the key with underscores. */
    public function field(): string
    {
        return str_replace('.', '_', $this->value);
    }

    public static function fromField(string $field): ?self
    {
        foreach (self::cases() as $key) {
            if ($key->field() === $field) {
                return $key;
            }
        }

        return null;
    }

    /** Is the value a whole number (stored as a number, not as text)? */
    public function isNumber(): bool
    {
        return is_int($this->default());
    }

    /**
     * What the form or the environment hands over, as it is compared: text is
     * trimmed (a display name with spaces around it is the name without them).
     */
    public function prepare(mixed $raw): mixed
    {
        return is_string($raw) ? trim($raw) : $raw;
    }

    /** @return list<string|ValidationRule> */
    public function rules(): array
    {
        $whole = ['required', 'integer', 'regex:/\A[0-9]{1,4}\z/'];

        return match ($this) {
            self::DisplayName => ['required', 'string', 'max:'.self::NAME_MAX, 'regex:/\A[^\p{Cc}\x{202A}-\x{202E}\x{2066}-\x{2069}]+\z/u'],
            self::DefaultLocale => ['required', 'string', 'in:'.implode(',', self::LOCALES)],
            self::TimeZone => ['required', 'string', 'timezone:all'],
            self::PageSize => [...$whole, 'in:'.implode(',', self::PAGE_SIZES)],
            self::DefaultTheme => ['required', 'string', 'in:'.implode(',', self::THEMES)],
            self::InvitationDays => [...$whole, 'between:1,'.self::INVITATION_DAYS_MAX],
            self::AuditRetentionDays => [...$whole, 'between:'.self::RETENTION_DAYS_MIN.','.self::RETENTION_DAYS_MAX],
            self::BackupsKeep => [...$whole, 'between:'.PreMigrateBackups::KEEP_MIN.','.PreMigrateBackups::KEEP_MAX],
        };
    }

    /** A value that passed the rules, as it is stored: a number for the numeric settings. */
    public function cast(mixed $valid): string|int
    {
        return $this->isNumber() ? (int) $valid : (string) $valid;
    }
}
