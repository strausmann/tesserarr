<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Authorization\Permission;
use App\Http\Ui\Audience;
use App\Http\Ui\CollapsibleSection;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use App\Updates\ReleaseCheck;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Inertia\Inertia;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * The asset version: the running build (TESSERARR_VERSION, plus the
     * commit when the image knows it), the same value the bundle is built
     * with (vite.config.ts) and the header X-Tesserarr-Version names. A visit the user starts from a tab of another
     * version becomes a full page load; a background request does not
     * (router.reload is async), the update notice covers that time.
     *
     * @see https://inertiajs.com/docs/v3/advanced/asset-versioning
     */
    public function version(Request $request): string
    {
        return AddVersionHeader::build();
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $user = $request->user();
        $user = $user instanceof User ? $user : null;
        $locale = App::getLocale();
        // Asked once per request through the gate (the Authorizer); the
        // navigation shows only what the account may open.
        $granted = $user === null ? [] : Audience::granted($user);
        $audience = $user === null ? Audience::Guest : Audience::fromGranted($granted);

        $instance = app(SettingsRegistry::class)->resolveMany([SettingKey::DisplayName, SettingKey::TimeZone]);

        return [
            ...parent::share($request),
            // The display name and time zone the operator set (general settings).
            'name' => $instance[SettingKey::DisplayName->value]->value,
            'timeZone' => $instance[SettingKey::TimeZone->value]->value,
            'auth' => [
                'user' => $request->user(),
            ],
            // The language SetLocale chose and its strings (lang/<locale>.json),
            // read per request: nothing here outlives it (worker-safe code).
            'locale' => $locale,
            'translations' => $this->translations($locale),
            'audience' => $audience->value,
            'permissions' => Permission::values($granted),
            'version' => AddVersionHeader::running(),
            // The newest published release, for the operator's "About"
            // dialog only. Optional: loaded by the dialog with a partial
            // reload, never with a page (App\Updates\ReleaseCheck).
            'release' => Inertia::optional(fn () => $audience === Audience::Operator
                ? app(ReleaseCheck::class)->latest()
                : null),
            'ui' => [
                // The operator's default theme (general settings); the client follows it until the person chooses.
                'themeDefault' => app(SettingsRegistry::class)->value(SettingKey::DefaultTheme),
                'collapsed' => $user !== null ? CollapsibleSection::known($user->ui_collapsed ?? []) : [],
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function translations(string $locale): array
    {
        /** @var array<string, string> $lines */
        $lines = app('translator')->getLoader()->load($locale, '*', '*');

        return $lines;
    }
}
