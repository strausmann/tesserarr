<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Audit\Actor;
use App\Http\Controllers\Controller;
use App\Models\User;
use App\Runtime\InstanceRole;
use App\Settings\ResolvedSetting;
use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use DateTimeZone;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Env;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The general settings page (Settings-General.dc.html): name, language, time
 * zone and the defaults of the interface, kept in the settings store and
 * logged on every change. What stays in the environment is shown below,
 * read-only: the platform URL, the trusted proxies and the role by value, the
 * keys and the database password only as set or not set. A value that is a
 * secret never leaves the server (the page props are visible to the browser).
 */
final class GeneralSettingsController extends Controller
{
    /** The secrets of the environment, shown as set or not set; never a value. */
    private const SECRETS = [
        'APP_KEY',
        'DB_PASSWORD',
        'TESSERARR_PII_KEY',
        'TESSERARR_BLIND_INDEX_KEY',
        'PASSKEYS_USER_HANDLE_SECRET',
    ];

    public function __construct(private readonly SettingsRegistry $settings) {}

    public function edit(Request $request): Response
    {
        $resolved = $this->settings->resolveAll();
        $client = (string) $request->ip();
        $peer = $request->server->getString('REMOTE_ADDR');

        return Inertia::render('admin/settings/General', [
            'settings' => collect($resolved)->mapWithKeys(fn (ResolvedSetting $setting) => [
                $setting->key->field() => [
                    'value' => $setting->value,
                    'source' => $setting->source->value,
                    'env' => $setting->key->env(),
                    'env_invalid' => $setting->envInvalid,
                    'default' => $setting->key->default(),
                ],
            ])->all(),
            'options' => [
                'time_zones' => DateTimeZone::listIdentifiers(),
                'locales' => SettingKey::LOCALES,
                'page_sizes' => SettingKey::PAGE_SIZES,
                'themes' => SettingKey::THEMES,
            ],
            'environment' => [
                ['env' => 'APP_URL', 'value' => (string) config('app.url')],
                ['env' => 'TESSERARR_TRUSTED_PROXIES', 'value' => implode(', ', (array) config('tesserarr.trusted_proxies'))],
                ['env' => InstanceRole::ENV, 'value' => (string) Env::get(InstanceRole::ENV, 'central')],
            ],
            'secrets' => array_map(fn (string $name) => ['env' => $name, 'set' => $this->isSet($name)], self::SECRETS),
            // How this request reached Tesserarr, for judging the proxy list:
            // the client address it sees and, behind a proxy, the proxy's.
            'request' => [
                'client' => $client,
                'proxy' => $peer !== '' && $peer !== $client ? $peer : null,
            ],
        ]);
    }

    public function update(Request $request, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->settings->update($request->only(array_map(fn (SettingKey $key) => $key->field(), SettingKey::cases())), Actor::user($actor));

        return back();
    }

    private function isSet(string $name): bool
    {
        $value = match ($name) {
            'APP_KEY' => config('app.key'),
            'DB_PASSWORD' => config('database.connections.'.config('database.default').'.password'),
            default => Env::get($name),
        };

        return is_string($value) && $value !== '';
    }
}
