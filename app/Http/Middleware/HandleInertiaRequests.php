<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Ui\Audience;
use App\Http\Ui\CollapsibleSection;
use App\Models\User;
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
     * The asset version: the running version (TESSERARR_VERSION), the same
     * value the bundle is built with (vite.config.ts) and the header
     * X-Tesserarr-Version names. A visit the user starts from a tab of another
     * version becomes a full page load; a background request does not
     * (router.reload is async), the update notice covers that time.
     *
     * @see https://inertiajs.com/docs/v3/advanced/asset-versioning
     */
    public function version(Request $request): string
    {
        return AddVersionHeader::running();
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
        $locale = App::getLocale();

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
            ],
            // The language SetLocale chose and its strings (lang/<locale>.json),
            // read per request: nothing here outlives it (worker-safe code).
            'locale' => $locale,
            'translations' => $this->translations($locale),
            'audience' => Audience::of($user instanceof User ? $user : null)->value,
            'version' => AddVersionHeader::running(),
            // The newest published release, for the operator's "About"
            // dialog only. Optional: loaded by the dialog with a partial
            // reload, never with a page (App\Updates\ReleaseCheck).
            'release' => Inertia::optional(fn () => Audience::of($user instanceof User ? $user : null) === Audience::Operator
                ? app(ReleaseCheck::class)->latest()
                : null),
            'ui' => [
                'collapsed' => $user instanceof User ? CollapsibleSection::known($user->ui_collapsed ?? []) : [],
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
