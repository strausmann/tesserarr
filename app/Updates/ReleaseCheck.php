<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Updates;

use App\Http\Middleware\AddVersionHeader;
use Illuminate\Contracts\Cache\Repository as Cache;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Whether a newer release of Tesserarr has been published.
 *
 * `refresh()` asks the GitHub releases API for the newest release that is
 * neither a draft nor a prerelease (`/releases/latest`), once per run of
 * `artisan tesserarr:release-check` (scheduled every six hours). The request
 * carries no token, cookie, address or version of this installation, only
 * the User-Agent GitHub requires, and gives up after the configured timeout.
 * The answer is trusted for one thing only: a tag of the form vX.Y.Z. Name,
 * text and links of the release are never read, the link the interface shows
 * is built here from the checked number.
 *
 * What is stored outlives a failed check: without a network the last known
 * release stays, with the time it was seen. Pages read the store only
 * (`latest()`), they never wait for GitHub. Built per call, no state between
 * requests (worker-safe code).
 */
final class ReleaseCheck
{
    public const CACHE_KEY = 'tesserarr.release.latest';

    private const TAG = '/^v?(0|[1-9]\d*)\.(0|[1-9]\d*)\.(0|[1-9]\d*)$/';

    public function __construct(
        private readonly Http $http,
        private readonly Cache $cache,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('tesserarr.release_check.enabled', true);
    }

    /**
     * Asks GitHub and stores the answer. Null when the check is off, the
     * request failed or the answer names no plain version; the stored release
     * is then left as it was.
     */
    public function refresh(): ?string
    {
        if (! $this->enabled()) {
            return null;
        }

        $version = $this->fetch();

        if ($version !== null) {
            $this->cache->forever(self::CACHE_KEY, [
                'version' => $version,
                'checked_at' => now()->toIso8601String(),
            ]);
        }

        return $version;
    }

    /**
     * What the interface shows under "About": the newest release, whether it
     * is newer than the running version, and its release page. Null when the
     * check is off or has never succeeded.
     *
     * @return array{latest: string, newer: bool, url: string, checkedAt: string}|null
     */
    public function latest(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $stored = $this->cache->get(self::CACHE_KEY);

        if (! is_array($stored) || ! is_string($stored['version'] ?? null) || ! self::plain($stored['version'])) {
            return null;
        }

        $version = $stored['version'];

        return [
            'latest' => $version,
            'newer' => version_compare($version, AddVersionHeader::running(), '>'),
            'url' => (string) config('tesserarr.release_check.releases').'v'.$version,
            'checkedAt' => (string) ($stored['checked_at'] ?? ''),
        ];
    }

    private function fetch(): ?string
    {
        $timeout = max(1, (int) config('tesserarr.release_check.timeout', 5));

        try {
            $response = $this->http
                ->withHeaders([
                    'User-Agent' => 'Tesserarr',
                    'Accept' => 'application/vnd.github+json',
                    'X-GitHub-Api-Version' => '2022-11-28',
                ])
                ->connectTimeout($timeout)
                ->timeout($timeout)
                ->withoutRedirecting()
                ->get((string) config('tesserarr.release_check.url'));
        } catch (Throwable $e) {
            Log::info('release check: GitHub not reachable', ['reason' => $e::class]);

            return null;
        }

        if (! $response->successful()) {
            Log::info('release check: GitHub answered with an error', ['status' => $response->status()]);

            return null;
        }

        $release = $response->json();
        $tag = is_array($release) ? ($release['tag_name'] ?? null) : null;

        if (! is_string($tag) || ! self::plain($tag) || ($release['draft'] ?? false) === true || ($release['prerelease'] ?? false) === true) {
            Log::info('release check: the answer names no released version');

            return null;
        }

        return ltrim($tag, 'v');
    }

    private static function plain(string $tag): bool
    {
        return preg_match(self::TAG, $tag) === 1;
    }
}
