<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /** Request attribute the root view (resources/views/app.blade.php) reads the theme from. */
    public const ATTRIBUTE = 'appearance';

    /** Request attribute for the instance's default theme (general setting ui.theme_default). */
    public const DEFAULT_ATTRIBUTE = 'appearance_default';

    /**
     * Handle an incoming request.
     *
     * The value is kept on the request: View::share() would keep it in the
     * view factory, which lives as long as the worker (CONTRIBUTING.md,
     * "Worker-safe code").
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $default = (string) app(SettingsRegistry::class)->value(SettingKey::DefaultTheme);
        $request->attributes->set(self::DEFAULT_ATTRIBUTE, $default);
        $request->attributes->set(self::ATTRIBUTE, $request->cookie('appearance') ?? $default);

        return $next($request);
    }
}
