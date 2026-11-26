<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Models\User;
use App\Settings\EnvOverride;
use App\Settings\SettingKey;
use App\Settings\Settings;
use App\Settings\SettingsRegistry;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Support\EnvSnapshot;

use function Tests\Support\generalForm;
use function Tests\Support\overrideWith;

afterEach(function () {
    isset($this->env) && $this->env->restore();
});

it('reads each override from its environment variable', function () {
    $this->env = new EnvSnapshot;
    $names = array_map(fn (SettingKey $key) => $key->env(), SettingKey::cases());
    $this->env->set(array_combine($names, array_map(fn (string $name) => 'from-'.$name, $names)));

    $map = (require config_path('tesserarr.php'))['setting_env'];

    expect(array_keys($map))->toBe($names)
        ->and($map['TZ'])->toBe('from-TZ')
        ->and($map['TESSERARR_BACKUP_KEEP'])->toBe('from-TESSERARR_BACKUP_KEEP');
});

it('lets an environment variable override and marks the field as overridden', function () {
    $operator = User::factory()->operator()->create();
    app(SettingsRegistry::class)->update(['ui_page_size' => 25], Actor::cli('ops'));
    overrideWith('TESSERARR_PAGE_SIZE', '100');

    expect(app(SettingsRegistry::class)->value(SettingKey::PageSize))->toBe(100);
    $this->actingAs($operator)->get('/settings/general')->assertInertia(fn (Assert $page) => $page
        ->where('settings.ui_page_size.value', 100)
        ->where('settings.ui_page_size.source', 'environment')
        ->where('settings.ui_page_size.env', 'TESSERARR_PAGE_SIZE')
        ->where('settings.ui_page_size.env_invalid', false)
        ->where('settings.time_zone.source', 'default'));
});

it('treats an empty environment variable as not set', function () {
    overrideWith('TESSERARR_PAGE_SIZE', '');

    $resolved = app(SettingsRegistry::class)->resolve(SettingKey::PageSize);

    // Not an invalid override that was ignored: no override at all.
    expect($resolved->source->value)->toBe('default')->and($resolved->envInvalid)->toBeFalse();
});

it('refuses to edit an overridden field in the ui', function () {
    overrideWith('TESSERARR_PAGE_SIZE', '100');
    $form = generalForm(['ui_page_size' => 25]);

    $this->actingAs(User::factory()->operator()->create())->from('/settings/general')
        ->put('/settings/general', $form)
        ->assertRedirect('/settings/general')
        ->assertSessionHasErrors(['ui_page_size' => __('settings.overridden', ['env' => 'TESSERARR_PAGE_SIZE'])]);

    // Nothing of the form was stored, the fields that were free included: one refusal refuses the save.
    expect(app(Settings::class)->get('ui.page_size'))->toBeNull()
        ->and(app(Settings::class)->get('time.zone'))->toBeNull()
        ->and(AuditEntry::query()->where('action', AuditAction::SettingChanged->value)->count())->toBe(0);
});

it('accepts the form of a page that left the overridden field out', function () {
    overrideWith('TESSERARR_PAGE_SIZE', '100');
    $form = generalForm();
    unset($form['ui_page_size']);

    $this->actingAs(User::factory()->operator()->create())
        ->put('/settings/general', $form)->assertSessionHasNoErrors();

    expect(app(Settings::class)->get('time.zone'))->toBe('Europe/Berlin')
        ->and(app(Settings::class)->get('ui.page_size'))->toBeNull();
});

it('refuses an overridden setting from the cli too', function () {
    overrideWith('TESSERARR_PAGE_SIZE', '100');

    $this->artisan('tesserarr:settings:set', ['key' => 'ui.page_size', 'value' => '25'])
        ->expectsOutputToContain('TESSERARR_PAGE_SIZE')->assertFailed();
    $this->artisan('tesserarr:settings:get', ['key' => 'ui.page_size'])
        ->expectsOutputToContain('ui.page_size = 100 (environment: TESSERARR_PAGE_SIZE)')->assertSuccessful();

    expect(app(Settings::class)->get('ui.page_size'))->toBeNull();
});

it('ignores an invalid environment value, keeps the field open and says so', function () {
    app(SettingsRegistry::class)->update(['ui_page_size' => 25], Actor::cli('ops'));
    overrideWith('TESSERARR_PAGE_SIZE', '17');

    expect(app(SettingsRegistry::class)->value(SettingKey::PageSize))->toBe(25);
    $this->actingAs(User::factory()->operator()->create())->get('/settings/general')
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.ui_page_size.source', 'stored')
            ->where('settings.ui_page_size.env_invalid', true));
});

it('shows APP_URL and TESSERARR_TRUSTED_PROXIES read-only and offers no way to change them in the ui', function () {
    config(['app.url' => 'https://tesserarr.example.org', 'tesserarr.trusted_proxies' => ['198.51.100.0/24', '192.0.2.10/32']]);
    $operator = User::factory()->operator()->create();

    $this->actingAs($operator)->get('/settings/general')->assertInertia(fn (Assert $page) => $page
        ->where('environment.0.env', 'APP_URL')
        ->where('environment.0.value', 'https://tesserarr.example.org')
        ->where('environment.1.env', 'TESSERARR_TRUSTED_PROXIES')
        ->where('environment.1.value', '198.51.100.0/24, 192.0.2.10/32'));

    // No setting, no field and no route changes them: the form is ignored where it names them.
    $this->put('/settings/general', generalForm(['app_url' => 'https://evil.example', 'tesserarr_trusted_proxies' => '0.0.0.0/0']))
        ->assertSessionHasNoErrors();
    $this->artisan('tesserarr:settings:set', ['key' => 'app.url', 'value' => 'https://evil.example'])->assertFailed();

    expect(config('app.url'))->toBe('https://tesserarr.example.org')
        ->and(config('tesserarr.trusted_proxies'))->toBe(['198.51.100.0/24', '192.0.2.10/32'])
        ->and(DB::table('app_settings')->whereIn('key', ['app.url', 'tesserarr.trusted_proxies', 'app_url', 'tesserarr_trusted_proxies'])->count())->toBe(0);
    foreach (SettingKey::cases() as $key) {
        expect($key->env())->not->toBe('APP_URL')->not->toBe('TESSERARR_TRUSTED_PROXIES');
    }
});

it('names the secrets of the environment as set or not set and never shows a value', function () {
    $secrets = [
        'APP_KEY' => 'base64:'.base64_encode(str_repeat('K', 32)),
        'DB_PASSWORD' => 'hunter2-db-password',
        'TESSERARR_PII_KEY' => 'pii-key-value-123',
        'TESSERARR_BLIND_INDEX_KEY' => null,
    ];
    config(['app.key' => $secrets['APP_KEY'], 'database.connections.pgsql.password' => $secrets['DB_PASSWORD']]);
    $this->env = (new EnvSnapshot)->set(['TESSERARR_PII_KEY' => $secrets['TESSERARR_PII_KEY'], 'TESSERARR_BLIND_INDEX_KEY' => null]);

    $response = $this->actingAs(User::factory()->operator()->create())->get('/settings/general')->assertOk();
    $page = (string) $response->getContent();

    expect($page)->not->toContain($secrets['DB_PASSWORD'])
        ->not->toContain((string) $secrets['TESSERARR_PII_KEY'])
        ->not->toContain($secrets['APP_KEY'])
        ->not->toContain(base64_encode(str_repeat('K', 32)));
    $response->assertInertia(fn (Assert $props) => $props
        ->where('secrets.0.env', 'APP_KEY')->where('secrets.0.set', true)
        ->where('secrets.1.env', 'DB_PASSWORD')->where('secrets.1.set', true)
        ->where('secrets.2.env', 'TESSERARR_PII_KEY')->where('secrets.2.set', true)
        ->where('secrets.3.env', 'TESSERARR_BLIND_INDEX_KEY')->where('secrets.3.set', false)
        ->missing('secrets.0.value'));
});

it('shows the operator how this request reached Tesserarr', function () {
    config(['tesserarr.trusted_proxies' => ['198.51.100.0/24']]);

    $this->actingAs(User::factory()->operator()->create())
        ->withServerVariables(['REMOTE_ADDR' => '203.0.113.7'])
        ->get('/settings/general')
        ->assertInertia(fn (Assert $page) => $page->where('request.client', '203.0.113.7')->where('request.proxy', null));
});

it('keeps the override reader free of state between requests', function () {
    overrideWith('TESSERARR_PAGE_SIZE', '100');
    $first = app(EnvOverride::class)->raw(SettingKey::PageSize);
    overrideWith('TESSERARR_PAGE_SIZE', null);

    expect($first)->toBe('100')
        ->and(app(EnvOverride::class)->raw(SettingKey::PageSize))->toBeNull();
});

it('says that tesserarr:migrate refuses to run when TESSERARR_BACKUP_KEEP is invalid, not that it is ignored', function () {
    overrideWith('TESSERARR_BACKUP_KEEP', '99');

    $this->actingAs(User::factory()->operator()->create())->get('/settings/general')
        ->assertInertia(fn (Assert $page) => $page
            ->where('settings.backups_keep.env_invalid', true)
            ->where('settings.backups_keep.env_refuses_migrate', true)
            ->where('settings.ui_page_size.env_refuses_migrate', false));

    $this->artisan('tesserarr:settings:get', ['key' => 'backups.keep'])
        ->expectsOutputToContain('tesserarr:migrate refuses to run')
        ->doesntExpectOutputToContain('ignored')
        ->assertSuccessful();
    // The other settings keep the old wording.
    overrideWith('TESSERARR_BACKUP_KEEP', null);
    overrideWith('TESSERARR_PAGE_SIZE', '17');
    $this->artisan('tesserarr:settings:get', ['key' => 'ui.page_size'])
        ->expectsOutputToContain('it is ignored')->assertSuccessful();
});

it('words the refusal in both languages', function () {
    $en = json_decode((string) file_get_contents(lang_path('en.json')), true);
    $de = json_decode((string) file_get_contents(lang_path('de.json')), true);

    expect($en['general.env_invalid_backups'])->toContain('tesserarr:migrate refuses to run')->not->toContain('ignored')
        ->and($de['general.env_invalid_backups'])->toContain('tesserarr:migrate')->toContain('verweigert')
        ->and($en['general.env_invalid'])->toContain('ignored');
});
