<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Second stage of the body limit (the first is Caddy's request_body
 * max_size of 2 MiB): 64 KiB for /api/* and /auth/*, answered with 413 before
 * routing and validation run.
 *
 * Two uses:
 *  - global, without parameter: applies the default to /api/* and /auth/*,
 *    unless the matched route declares its own limit;
 *  - on a route as "limit.body:<bytes>": applies that limit instead.
 *
 * Both the declared Content-Length and the bytes actually read count; a
 * client that sends more than it declares is cut off at limit + 1 bytes.
 */
final class LimitRequestBody
{
    public const DEFAULT_LIMIT = 65_536;

    public const ALIAS = 'limit.body';

    /** @var list<string> */
    private const PATHS = ['api/*', 'auth/*'];

    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next, ?string $limit = null): Response
    {
        if ($limit === null) {
            if (! $request->is(...self::PATHS) || $this->routeDeclaresOwnLimit($request)) {
                return $next($request);
            }

            $limit = self::DEFAULT_LIMIT;
        }

        if ($this->exceeds($request, (int) $limit)) {
            return response()->json(['error' => 'payload_too_large'], Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }

    private function exceeds(Request $request, int $limit): bool
    {
        $declared = $request->server->get('CONTENT_LENGTH', $request->headers->get('Content-Length'));

        if (is_numeric($declared) && (int) $declared > $limit) {
            return true;
        }

        $stream = $request->getContent(true);
        $read = 0;

        while (! feof($stream) && $read <= $limit) {
            $chunk = fread($stream, 8192);

            if ($chunk === false) {
                break;
            }

            $read += strlen($chunk);
        }

        return $read > $limit;
    }

    private function routeDeclaresOwnLimit(Request $request): bool
    {
        try {
            $route = $this->router->getRoutes()->match($request);
        } catch (HttpException) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (is_string($middleware) && str_starts_with($middleware, self::ALIAS.':')) {
                return true;
            }
        }

        return false;
    }
}
