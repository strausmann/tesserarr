<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Http\Request;
use Tests\Support\Worker\UserRequest;

// Every page that shows user-specific data, served to two users by one worker
// (CONTRIBUTING.md, "Worker-safe code"). A new page with user data adds its
// path here.

/**
 * @return Closure(): UserRequest
 */
function pageAs(string $name, string $path): Closure
{
    return function () use ($name, $path): UserRequest {
        $user = workerUser($name);

        return UserRequest::as($user, Request::create($path), $name, (string) $user->getAttribute('email'));
    };
}

it('shows each user only their own data', function (string $path) {
    assertNoStateLeakBetweenUsers(pageAs('Alice', $path), pageAs('Bob', $path));
})->with([
    'dashboard' => '/dashboard',
    'profile settings' => '/settings/profile',
    'appearance settings' => '/settings/appearance',
]);

it('shows each user only their own data when they open different pages', function (string $pathOfA, string $pathOfB) {
    assertNoStateLeakBetweenUsers(pageAs('Alice', $pathOfA), pageAs('Bob', $pathOfB));
})->with([
    'profile, then dashboard' => ['/settings/profile', '/dashboard'],
    'appearance, then profile' => ['/settings/appearance', '/settings/profile'],
]);
