<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

/**
 * Chooses the language of the interface for this request: the language of the
 * signed-in user's profile, else the best match of the browser's
 * Accept-Language (de-CH counts as de), else the default language of the
 * instance (general setting locale.default, English until it is set).
 *
 * The locale is set per request here, the only place it may be set
 * (CONTRIBUTING.md, "Worker-safe code"); Octane resets it after the request.
 * The same value goes to the page as the Inertia prop `locale`
 * (HandleInertiaRequests), so server and client never disagree.
 */
final class SetLocale
{
    /** The languages of lang/*.json, the first is the fallback. */
    public const SUPPORTED = ['en', 'de'];

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        App::setLocale(self::resolve($request));

        return $next($request);
    }

    public static function resolve(Request $request): string
    {
        $user = $request->user();
        if ($user instanceof User && in_array($user->locale, self::SUPPORTED, true)) {
            return $user->locale;
        }

        // The language a browser asks for, when it names one of ours
        // (Symfony matches de_CH to de); otherwise the instance's default
        // language (the general setting locale.default).
        foreach ($request->getLanguages() as $asked) {
            $language = strtolower(substr($asked, 0, 2));
            if (in_array($language, self::SUPPORTED, true)) {
                return $language;
            }
        }

        $default = app(SettingsRegistry::class)->value(SettingKey::DefaultLocale);

        return in_array($default, self::SUPPORTED, true) ? (string) $default : self::SUPPORTED[0];
    }
}
