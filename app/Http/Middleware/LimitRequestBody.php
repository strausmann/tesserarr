<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Second stage of the body limit (the first is Caddy's request_body
 * max_size of 2 MiB): 64 KiB for /api/* and /auth/*, answered before routing
 * and validation run.
 *
 * Two uses:
 *  - global, without parameter: applies the default to /api/* and /auth/*,
 *    unless the matched route declares its own limit;
 *  - on a route as "limit.body:<bytes>" or "limit.body:<bytes>,multipart":
 *    applies that limit instead; "multipart" allows multipart/form-data.
 *
 * Checks, in this order:
 *  - 411 for a body method without Content-Length. FrankenPHP hands a
 *    chunked request over with an empty CONTENT_LENGTH and without the
 *    Transfer-Encoding header, so the missing length is the only sign.
 *  - 415 for multipart/form-data unless the route allows it. PHP parses
 *    multipart bodies before the application runs and php://input is then
 *    empty, so the stream check below would measure nothing.
 *  - 413 when the declared Content-Length, the bytes actually read from the
 *    stream, or the parsed files and parameters exceed the limit. A client
 *    that sends more than it declares is cut off at limit + 1 bytes.
 */
final class LimitRequestBody
{
    public const DEFAULT_LIMIT = 65_536;

    public const ALIAS = 'limit.body';

    public const ALLOW_MULTIPART = 'multipart';

    /** @var list<string> */
    private const PATHS = ['api/*', 'auth/*'];

    /** @var list<string> */
    private const BODY_METHODS = ['POST', 'PUT', 'PATCH'];

    public function __construct(private readonly Router $router) {}

    public function handle(Request $request, Closure $next, ?string $limit = null, ?string $option = null): Response
    {
        if ($limit === null) {
            if (! $request->is(...self::PATHS) || $this->routeDeclaresOwnLimit($request)) {
                return $next($request);
            }

            $limit = self::DEFAULT_LIMIT;
        }

        if ($this->lengthMissing($request)) {
            return self::reject('length_required', Response::HTTP_LENGTH_REQUIRED);
        }

        if ($option !== self::ALLOW_MULTIPART && $this->isMultipart($request)) {
            return self::reject('unsupported_media_type', Response::HTTP_UNSUPPORTED_MEDIA_TYPE);
        }

        if ($this->exceeds($request, (int) $limit)) {
            return self::reject('payload_too_large', Response::HTTP_REQUEST_ENTITY_TOO_LARGE);
        }

        return $next($request);
    }

    private static function reject(string $error, int $status): Response
    {
        return response()->json(['error' => $error], $status);
    }

    private function lengthMissing(Request $request): bool
    {
        if (str_contains(strtolower((string) $request->headers->get('Transfer-Encoding', '')), 'chunked')) {
            return true;
        }

        return in_array($request->getMethod(), self::BODY_METHODS, true)
            && ! is_numeric(self::declaredLength($request));
    }

    /**
     * Symfony's HeaderBag holds CONTENT_LENGTH from the server variables as
     * the Content-Length header, so both sources are covered.
     */
    private static function declaredLength(Request $request): ?string
    {
        return $request->headers->get('Content-Length');
    }

    private function isMultipart(Request $request): bool
    {
        return str_starts_with(strtolower((string) $request->headers->get('Content-Type', '')), 'multipart/');
    }

    private function exceeds(Request $request, int $limit): bool
    {
        $declared = self::declaredLength($request);

        if (is_numeric($declared) && (int) $declared > $limit) {
            return true;
        }

        if ($this->parsedSize($request) > $limit) {
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

    /**
     * Size of what PHP already parsed out of the body: uploaded files plus the
     * form parameters, encoded as they would travel.
     */
    private function parsedSize(Request $request): int
    {
        $size = strlen(http_build_query($request->request->all()));

        $files = $request->files->all();

        array_walk_recursive($files, function ($file) use (&$size): void {
            if ($file instanceof UploadedFile) {
                $size += (int) $file->getSize();
            }
        });

        return $size;
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
