<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http;

/**
 * The Host headers the instance answers to (TrustHosts, bootstrap/app.php):
 * the host of APP_URL, the loopback names the health check inside the
 * container uses, and TESSERARR_TRUSTED_HOSTS. Any other Host header is
 * refused, so a forged one cannot reach a page or a link (task 8a). Read from
 * the configuration on every call; nothing is kept.
 */
final class TrustedHosts
{
    public const LOOPBACK = ['localhost', '127.0.0.1', '[::1]'];

    /** @return list<string> regular expressions, one per host, anchored */
    public static function patterns(): array
    {
        $app = parse_url((string) config('app.url'), PHP_URL_HOST);
        $extra = config('tesserarr.trusted_hosts');

        $hosts = array_merge(
            is_string($app) && $app !== '' ? [$app] : [],
            self::LOOPBACK,
            is_array($extra) ? array_filter($extra, 'is_string') : [],
        );

        return array_values(array_unique(array_map(
            fn (string $host) => '^'.preg_quote(strtolower($host), '/').'$',
            $hosts,
        )));
    }
}
