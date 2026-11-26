<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditLogger;
use App\Authorization\Roles;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

use function Tests\Support\generalForm;
use function Tests\Support\protectedRoutes;
use function Tests\Support\settingField as field;

it('stores and reads each general setting with its default', function (SettingKey $key, string|int $default, string|int $changed) {
    $registry = app(SettingsRegistry::class);

    expect($registry->value($key))->toBe($default)
        ->and($registry->resolve($key)->source->value)->toBe('default');

    $registry->update([field($key) => $changed], Actor::cli('ops'));

    expect($registry->value($key))->toBe($changed)
        ->and($registry->resolve($key)->source->value)->toBe('stored')
        ->and(app(Settings::class)->get($key->value))->toBe($changed);
})->with([
    'display name' => [SettingKey::DisplayName, 'Tesserarr', 'Home base'],
    'default language' => [SettingKey::DefaultLocale, 'en', 'de'],
    'time zone' => [SettingKey::TimeZone, 'UTC', 'Europe/Berlin'],
    'page size' => [SettingKey::PageSize, 50, 100],
    'default theme' => [SettingKey::DefaultTheme, 'system', 'dark'],
    'invitation days' => [SettingKey::InvitationDays, 7, 30],
    'audit retention' => [SettingKey::AuditRetentionDays, 365, 90],
    'backups to keep' => [SettingKey::BackupsKeep, 5, 12],
]);

it('knows exactly the eight general settings', function () {
    expect(array_map(fn (SettingKey $key) => $key->value, SettingKey::cases()))->toBe([
        'instance.display_name', 'locale.default', 'time.zone', 'ui.page_size',
        'ui.theme_default', 'invitations.default_days', 'audit.retention_days', 'backups.keep',
    ]);
});

it('shows the page to an operator with every setting, its source and the options', function () {
    $this->actingAs(User::factory()->operator()->create())
        ->get('/settings/general')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/settings/General')
            ->where('settings.ui_page_size.value', 50)
            ->where('settings.ui_page_size.source', 'default')
            ->where('settings.ui_page_size.env', 'TESSERARR_PAGE_SIZE')
            ->where('settings.time_zone.value', 'UTC')
            ->where('settings.backups_keep.value', 5)
            ->has('options.time_zones')
            ->where('options.page_sizes', [25, 50, 100]));
});

it('saves the changed fields from the ui and logs one entry per changed key, never a value', function () {
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->from('/settings/general')
        ->put('/settings/general', generalForm(['instance_display_name' => ' Home base ']))
        ->assertRedirect('/settings/general')
        ->assertSessionHasNoErrors();

    $registry = app(SettingsRegistry::class);
    expect($registry->value(SettingKey::DisplayName))->toBe('Home base')
        ->and($registry->value(SettingKey::TimeZone))->toBe('Europe/Berlin')
        ->and($registry->value(SettingKey::PageSize))->toBe(100)
        ->and($registry->value(SettingKey::InvitationDays))->toBe(14)
        ->and($registry->value(SettingKey::BackupsKeep))->toBe(7);

    $entries = AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->get();
    // Every field of the form differs from what was in force.
    expect($entries)->toHaveCount(8)
        ->and($entries->pluck('actor_id')->unique()->all())->toBe([$operator->id])
        ->and($entries->pluck('actor_type')->unique()->all())->toBe(['user'])
        ->and(json_encode($entries->pluck('context')))->not->toContain('Home base')
        ->not->toContain('Berlin');
});

it('logs nothing for a field that did not change', function () {
    $this->actingAs(User::factory()->operator()->create())
        ->put('/settings/general', generalForm())->assertSessionHasNoErrors();
    $logged = AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->count();

    $this->put('/settings/general', generalForm())->assertSessionHasNoErrors();

    expect($logged)->toBe(7)
        ->and(AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->count())->toBe(7);
});

it('refuses invalid values with the field named and stores nothing', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->operator()->create())->from('/settings/general')
        ->put('/settings/general', generalForm([$field => $value]))
        ->assertRedirect('/settings/general')
        ->assertSessionHasErrors($field);

    expect(app(Settings::class)->get('ui.page_size'))->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->count())->toBe(0);
})->with([
    'a time zone nobody knows' => ['time_zone', 'Mars/Olympus'],
    'a number as a time zone' => ['time_zone', '3600'],
    'a page size off the list' => ['ui_page_size', 33],
    'a page size as text' => ['ui_page_size', 'many'],
    'a theme off the list' => ['ui_theme_default', 'neon'],
    'a language off the list' => ['locale_default', 'fr'],
    'no display name' => ['instance_display_name', '   '],
    'a display name with a control character' => ['instance_display_name', "Evil\x1b[31m"],
    'a display name of 61 characters' => ['instance_display_name', str_repeat('a', 61)],
    'zero invitation days' => ['invitations_default_days', '0'],
    'invitation days with a decimal' => ['invitations_default_days', '7.5'],
    'zero backups to keep' => ['backups_keep', '0'],
    'more than fifty backups to keep' => ['backups_keep', '51'],
    'backups to keep as a word' => ['backups_keep', 'five'],
    'a negative retention' => ['audit_retention_days', '-1'],
]);

it('accepts the limits of each range', function (string $field, mixed $value) {
    $this->actingAs(User::factory()->operator()->create())
        ->put('/settings/general', generalForm([$field => $value]))
        ->assertSessionHasNoErrors();
})->with([
    ['backups_keep', '1'],
    ['backups_keep', '50'],
    ['instance_display_name', str_repeat('ä', 60)],
    ['time_zone', 'UTC'],
]);

it('lets only an account with settings.manage open and save the page', function () {
    $friend = User::factory()->withRole(Roles::USER)->create();

    // 403 with the Authorizer's reason: the account lacks the permission (not a lock, not a token).
    $this->actingAs($friend)->getJson('/settings/general')
        ->assertForbidden()->assertJsonPath('message', 'owner_lacks_permission');
    $this->actingAs($friend)->putJson('/settings/general', generalForm())
        ->assertForbidden()->assertJsonPath('message', 'owner_lacks_permission');

    expect(app(Settings::class)->get('time.zone'))->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->count())->toBe(0);
    auth()->logout();
    $this->get('/settings/general')->assertRedirect('/login');
});

it('uses ui.page_size for paginated lists', function () {
    $operator = User::factory()->operator()->create();
    foreach (range(1, 60) as $n) {
        app(AuditLogger::class)->record(AuditAction::UserLocked, Actor::user($operator), null, ['n' => $n]);
    }
    $registry = app(SettingsRegistry::class);

    $this->actingAs($operator)->get('/audit')->assertInertia(fn (Assert $page) => $page->has('entries', 50));

    $registry->update(['ui_page_size' => 25], Actor::cli('ops'));
    $this->get('/audit')->assertInertia(fn (Assert $page) => $page->has('entries', 25));

    $registry->update(['ui_page_size' => 100], Actor::cli('ops'));
    // Sixty entries plus the two settings changes of this test.
    $this->get('/audit')->assertInertia(fn (Assert $page) => $page->has('entries', 62));
});

it('shows the display name, the time zone and the default theme where they are used', function () {
    $registry = app(SettingsRegistry::class);
    $registry->update(['instance_display_name' => 'Home base', 'time_zone' => 'Europe/Berlin', 'ui_theme_default' => 'dark'], Actor::cli('ops'));

    $this->get('/login')
        ->assertInertia(fn (Assert $page) => $page->where('name', 'Home base')->where('timeZone', 'Europe/Berlin'));
    expect(app(SettingsRegistry::class)->value(SettingKey::DefaultTheme))->toBe('dark');
});

it('hands the default theme to the page, so a saved change reaches open tabs', function () {
    $operator = User::factory()->operator()->create();
    $this->actingAs($operator)->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('ui.themeDefault', 'system'));

    $this->put('/settings/general', generalForm(['ui_theme_default' => 'dark']))->assertSessionHasNoErrors();

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('ui.themeDefault', 'dark'));
});

it('uses the default language when the profile and the browser name none', function () {
    $user = User::factory()->operator()->create(['locale' => null]);
    app(SettingsRegistry::class)->update(['locale_default' => 'de'], Actor::cli('ops'));

    // The test client asks for English unless told otherwise; a browser in French names none of ours.
    $this->actingAs($user)->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'de'));
    // The profile wins over the instance default.
    $user->forceFill(['locale' => 'en'])->save();
    $this->withHeaders(['Accept-Language' => 'fr-FR,fr;q=0.9'])->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
    // So does a language the browser asks for.
    $user->forceFill(['locale' => null])->save();
    $this->withHeaders(['Accept-Language' => 'en-GB,en;q=0.8'])->get('/dashboard')
        ->assertInertia(fn (Assert $page) => $page->where('locale', 'en'));
});

it('reads backups.keep before the settings table exists', function () {
    Schema::drop('app_settings');

    expect(app(SettingsRegistry::class)->backupKeep())->toBe(5);
});

it('sets and gets a setting from the cli and audits it', function () {
    $this->artisan('tesserarr:settings:get', ['key' => 'ui.page_size'])
        ->expectsOutputToContain('ui.page_size = 50 (default)')->assertSuccessful();

    $this->artisan('tesserarr:settings:set', ['key' => 'ui.page_size', 'value' => '100'])
        ->expectsOutputToContain('ui.page_size = 100')->assertSuccessful();

    $this->artisan('tesserarr:settings:get', ['key' => 'ui.page_size'])
        ->expectsOutputToContain('ui.page_size = 100 (stored)')->assertSuccessful();

    $entry = AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->sole();
    expect($entry->actor_type)->toBe('cli')
        ->and($entry->context)->toBe(['key' => 'ui.page_size'])
        ->and(app(Settings::class)->get('ui.page_size'))->toBe(100);
});

it('lists every setting from the cli when no key is given', function () {
    $this->artisan('tesserarr:settings:get')
        ->expectsOutputToContain('instance.display_name = Tesserarr (default)')
        ->expectsOutputToContain('backups.keep = 5 (default)')
        ->assertSuccessful();
});

it('refuses an unknown key and an invalid value from the cli and changes nothing', function () {
    $this->artisan('tesserarr:settings:set', ['key' => 'app.url', 'value' => 'https://evil.example'])
        ->expectsOutputToContain('No general setting "app.url"')->assertFailed();
    $this->artisan('tesserarr:settings:get', ['key' => 'tesserarr.trusted_proxies'])
        ->expectsOutputToContain('No general setting')->assertFailed();
    $this->artisan('tesserarr:settings:set', ['key' => 'ui.page_size', 'value' => '33'])
        ->expectsOutputToContain('ui_page_size')->assertFailed();

    expect(AuditEntry::query()->count())->toBe(0)
        ->and(app(Settings::class)->get('ui.page_size'))->toBeNull();
});

it('overwrites a stored value that is invalid when the default is saved', function () {
    // A row an older version or a hand edit left behind: not a page size.
    DB::table('app_settings')->insert(['key' => 'ui.page_size', 'value' => json_encode(17), 'updated_at' => now()]);
    $registry = app(SettingsRegistry::class);
    expect($registry->value(SettingKey::PageSize))->toBe(50);

    // 50 is what is in force, but the stored 17 is what the next fixed default would meet.
    $changed = $registry->update(['ui_page_size' => 50], Actor::cli('ops'));

    expect($changed)->toBe([SettingKey::PageSize])
        ->and(app(Settings::class)->get('ui.page_size'))->toBe(50);
});

it('reads the settings with one query per request', function () {
    $operator = User::factory()->operator()->create();
    app(SettingsRegistry::class)->update(['ui_theme_default' => 'dark', 'locale_default' => 'de'], Actor::cli('ops'));
    $this->actingAs($operator);

    DB::enableQueryLog();
    $this->get('/dashboard')->assertOk();
    $reads = collect(DB::getQueryLog())->filter(fn (array $q) => str_contains($q['query'], '"app_settings"'))->count();
    DB::disableQueryLog();

    expect($reads)->toBe(1);
});

it('does not keep a setting from one request to the next', function () {
    $operator = User::factory()->operator()->create();
    $registry = app(SettingsRegistry::class);
    $this->actingAs($operator);

    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('name', 'Tesserarr'));
    $registry->update(['instance_display_name' => 'Home base'], Actor::cli('ops'));
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('name', 'Home base'));
    // Changed behind the registry's back (another process): the next request still sees it.
    DB::table('app_settings')->where('key', 'instance.display_name')->update(['value' => json_encode('Elsewhere')]);
    $this->get('/dashboard')->assertInertia(fn (Assert $page) => $page->where('name', 'Elsewhere'));
});

it('has both routes of the page among the routes the Authorizer coverage test enumerates', function () {
    $covered = collect(protectedRoutes())->map(fn ($route) => implode('|', array_diff($route->methods(), ['HEAD'])).' '.$route->uri())->all();

    expect($covered)->toContain('GET settings/general')->toContain('PUT settings/general');
});
