<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Ui\Audience;
use App\Http\Ui\CollapsibleSection;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
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
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
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
            'version' => config('app.version'),
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
