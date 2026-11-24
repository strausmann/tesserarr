<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * One line per request on the "access" channel (stdout), in nginx
 * "combined" format:
 *
 *   $remote_addr - $remote_user [$time_local] "$request" $status $body_bytes_sent "$http_referer" "$http_user_agent"
 *
 * The address is the real client: TrustProxies resolves X-Forwarded-For
 * from the proxies in TESSERARR_TRUSTED_PROXIES only. The user field is
 * always "-" and query string values are redacted, so the log carries no
 * identities or tokens. Runs first in the global stack, so responses that
 * other middleware short-circuits (413, 503) are logged too.
 *
 * Format: https://nginx.org/en/docs/http/ngx_http_log_module.html#log_format
 */
final class AccessLog
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        Log::channel('access')->info(self::line($request, $response));

        return $response;
    }

    private static function line(Request $request, Response $response): string
    {
        return sprintf(
            '%s - - [%s] "%s %s %s" %d %s "%s" "%s"',
            $request->ip() ?? '-',
            date('d/M/Y:H:i:s O'),
            self::escape($request->getMethod()),
            self::escape(self::target($request)),
            self::escape($request->getProtocolVersion() ?? 'HTTP/1.1'),
            $response->getStatusCode(),
            self::bytes($response),
            self::escape((string) ($request->headers->get('Referer') ?? '-')),
            self::escape((string) ($request->headers->get('User-Agent') ?? '-')),
        );
    }

    private static function target(Request $request): string
    {
        $query = $request->server->get('QUERY_STRING', '');

        if ($query === '' || $query === null) {
            return $request->getPathInfo();
        }

        $redacted = array_map(
            static fn (string $pair) => explode('=', $pair, 2)[0].'=[redacted]',
            array_filter(explode('&', (string) $query), static fn (string $pair) => $pair !== ''),
        );

        return $request->getPathInfo().'?'.implode('&', $redacted);
    }

    private static function bytes(Response $response): string
    {
        if ($response instanceof StreamedResponse || $response instanceof BinaryFileResponse) {
            return (string) ($response->headers->get('Content-Length') ?? '-');
        }

        return (string) strlen((string) $response->getContent());
    }

    /**
     * Same rule as nginx: quote and backslash are escaped, every byte outside
     * printable ASCII becomes \xHH, so no header can start a new log line.
     */
    private static function escape(string $value): string
    {
        return (string) preg_replace_callback(
            '/[^\x20-\x7E]|["\\\\]/',
            static fn (array $match) => match ($match[0]) {
                '"' => '\\"',
                '\\' => '\\\\',
                default => sprintf('\\x%02X', ord($match[0])),
            },
            $value,
        );
    }
}
