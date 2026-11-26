<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Database\ConnectionInterface;

/**
 * The operator's settings, stored in app_settings as JSON per key
 * (pre-flight K28). Later tasks read their switches through it: sign-in
 * methods (11), the global MFA switch (12), mail without a key (10a), Plex and
 * the header sign-in (14, 15), general settings (8b).
 *
 * Every change is logged with the key, never with the value: a setting can
 * be anything, and the log never carries what might be a secret. Who may
 * change a setting is decided where the change comes in: the route checks
 * `settings.manage` through the gate, the console counts as the operator.
 *
 * Reads go to the database each time; nothing is kept between requests.
 */
final class Settings
{
    private const KEY_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)+\z/';

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly AuditLogger $audit,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->db->table('app_settings')->where('key', $key)->value('value');

        return is_string($value) ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : $default;
    }

    /** @throws UnknownSetting */
    public function set(string $key, mixed $value, Actor $actor): void
    {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new UnknownSetting($key);
        }

        $this->db->transaction(function () use ($key, $value, $actor) {
            $this->db->table('app_settings')->upsert([[
                'key' => $key,
                'value' => json_encode($value, JSON_THROW_ON_ERROR),
                'updated_by' => $actor->type === Actor::USER ? $actor->id : null,
                'updated_at' => now(),
            ]], ['key'], ['value', 'updated_by', 'updated_at']);

            $this->audit->record(AuditAction::SettingChanged, $actor, null, ['key' => $key]);
        });
    }
}
