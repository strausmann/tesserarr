<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditEntry;
use App\Models\User;
use App\Settings\Settings;
use App\Settings\UnknownSetting;

it('returns the default for a setting nobody has set', function () {
    expect(app(Settings::class)->get('email.allowed', false))->toBeFalse();
});

it('stores a value, reads it back and logs who changed which key', function () {
    $operator = User::factory()->operator()->create();

    app(Settings::class)->set('email.allowed', true, Actor::user($operator));

    $entry = AuditEntry::query()->where('action', 'setting.update')->sole();
    expect(app(Settings::class)->get('email.allowed', false))->toBeTrue()
        ->and($entry->context)->toBe(['key' => 'email.allowed'])
        ->and($entry->actor_id)->toBe($operator->id);
    $this->assertDatabaseHas('app_settings', ['key' => 'email.allowed', 'updated_by' => $operator->id]);
});

it('keeps structured values', function () {
    app(Settings::class)->set('login.methods', ['password' => true, 'passkey' => false], Actor::cli('ops'));

    // jsonb keeps no key order.
    expect(app(Settings::class)->get('login.methods'))->toEqual(['password' => true, 'passkey' => false]);
});

it('refuses a key that is not a dotted lower-case name', function () {
    app(Settings::class)->set('Email Allowed', true, Actor::cli('ops'));
})->throws(UnknownSetting::class);
