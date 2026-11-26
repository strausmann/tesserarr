<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

// Every target of the main navigation (resources/js/lib/navigation.ts) is a
// page that exists. A target whose page a later task of the milestone builds
// is listed here with that task; the list shrinks as the pages arrive and is
// empty before a release: the run of the milestone pull request into `main`
// (and every run on `main`) fails while anything is left in it.

/** Target => the task of 0.1.0 that builds its page. */
const NAVIGATION_PENDING = [
    '/catalog' => 'task 21 (catalogue)',
    '/connections' => 'task 19 (connections)',
    '/media-shares' => 'task 17 (media shares)',
    '/satellites' => 'task 18 (satellites)',
    '/settings/sign-in' => 'task 13 (sign-in methods)',
    '/status' => 'task 23 (status)',
    '/tokens' => 'task 9 (API tokens)',
];

/**
 * The hrefs written in navigation.ts.
 *
 * @return list<string>
 */
function navigationTargets(): array
{
    $source = (string) file_get_contents(base_path('resources/js/lib/navigation.ts'));
    preg_match_all("/href: '([^']+)'/", $source, $matches);

    return array_values(array_unique($matches[1]));
}

function hasPage(string $path): bool
{
    try {
        $route = Route::getRoutes()->match(Request::create($path, 'GET'));
    } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
        return false;
    }

    return ! $route->isFallback;
}

/** The run checks what goes to `main`: the milestone pull request, or `main` itself. */
function runTargetsMain(): bool
{
    return getenv('GITHUB_BASE_REF') === 'main' || getenv('GITHUB_REF') === 'refs/heads/main';
}

it('reads the targets from navigation.ts', function () {
    expect(navigationTargets())->toContain('/dashboard', '/shared', '/settings/profile');
});

it('leads every navigation target to a page or names the task that builds it', function () {
    $missing = array_values(array_filter(
        navigationTargets(),
        fn (string $path) => ! hasPage($path) && ! array_key_exists($path, NAVIGATION_PENDING),
    ));

    expect($missing)->toBe([], 'Navigation targets without a page: '.implode(', ', $missing));
});

it('lists only targets that are still in the navigation and still have no page', function () {
    $targets = navigationTargets();
    $stale = array_values(array_filter(
        array_keys(NAVIGATION_PENDING),
        fn (string $path) => ! in_array($path, $targets, true) || hasPage($path),
    ));

    expect($stale)->toBe([], 'Remove from NAVIGATION_PENDING: '.implode(', ', $stale));
});

it('has no pending navigation target when the run goes to main', function () {
    expect(runTargetsMain() ? array_keys(NAVIGATION_PENDING) : [])->toBe([]);
});
