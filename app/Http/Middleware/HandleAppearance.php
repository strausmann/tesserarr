<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class HandleAppearance
{
    /** Request attribute the root view (resources/views/app.blade.php) reads the theme from. */
    public const ATTRIBUTE = 'appearance';

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
        $request->attributes->set(self::ATTRIBUTE, $request->cookie('appearance') ?? 'system');

        return $next($request);
    }
}
