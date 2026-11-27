<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Http\Request;
use Tests\Support\Worker\CommittedUsers;
use Tests\Support\Worker\OneWorker;
use Tests\Support\Worker\UserRequest;

// Every page that shows user-specific data, served to two users by one worker
// (CONTRIBUTING.md, "Worker-safe code"). A new page with user data adds its
// path here. Alice and Bob are committed as operators: the pages ask the
// Authorizer, which reads the account and its roles from the database.

function aliceAndBob(): Closure
{
    return CommittedUsers::operators(workerUser('Alice'), workerUser('Bob'));
}

/**
 * @return Closure(): UserRequest
 */
function pageAs(string $name, string $path): Closure
{
    return function () use ($name, $path): UserRequest {
        $user = workerUser($name);

        // Only "My account" shows the own address; every page shows the name.
        $secrets = $path === '/account' ? [$name, workerEmail($name)] : [$name];

        return UserRequest::as($user, Request::create($path), ...$secrets);
    };
}

it('shows each user only their own data', function (string $path) {
    assertNoStateLeakBetweenUsers(pageAs('Alice', $path), pageAs('Bob', $path), aliceAndBob());
})->with([
    'dashboard' => '/dashboard',
    // The user list and the audit log name every account by design, so they
    // cannot be checked with "Bob sees nothing of Alice"; the roles page can.
    'roles' => '/roles',
    // Shows how the request of the signed-in user reached the instance.
    'general settings' => '/settings/general',
    'profile settings' => '/settings/profile',
    // My account (task 8a): the own address in plain text, nobody else's.
    'my account' => '/account',
    'my activity' => '/account/activity',
    'appearance settings' => '/settings/appearance',
]);

it('shows each user only their own data when they open different pages', function (string $pathOfA, string $pathOfB) {
    assertNoStateLeakBetweenUsers(pageAs('Alice', $pathOfA), pageAs('Bob', $pathOfB), aliceAndBob());
})->with([
    'profile, then dashboard' => ['/settings/profile', '/dashboard'],
    'appearance, then profile' => ['/settings/appearance', '/settings/profile'],
]);

// The language and the collapsed sections are per user (SetLocale,
// HandleInertiaRequests): Alice reads German, Bob English, and nothing of
// Alice's choice may reach Bob or a guest through the worker.
it('keeps the language and the collapsed sections of one user away from the next', function () {
    $alice = workerUser('Alice');
    $alice->setRawAttributes([...$alice->getAttributes(), 'locale' => 'de', 'ui_collapsed' => '{"dashboard.attention":true}'], true);
    $bob = workerUser('Bob');
    $bob->setRawAttributes([...$bob->getAttributes(), 'locale' => 'en', 'ui_collapsed' => '{}'], true);

    assertNoStateLeakBetweenUsers(
        // German strings of the frame and Alice's collapsed section.
        fn () => UserRequest::as($alice, Request::create('/dashboard'), 'Alice', 'Hauptnavigation', 'dashboard.attention'),
        fn () => UserRequest::as($bob, Request::create('/dashboard'), 'Bob', 'Main navigation'),
        aliceAndBob(),
    );
});

// The guest step of assertNoStateLeakBetweenUsers() repeats the page without
// a user, which redirects to /login and renders nothing. So the guest gets
// its own rendered page here: after Alice (German) the worker serves the
// sign-in to a guest whose browser names no language, and it must be English.
it('serves a guest after a German user in English', function () {
    $alice = workerUser('Alice');
    $alice->setRawAttributes([...$alice->getAttributes(), 'locale' => 'de', 'ui_collapsed' => '{}'], true);

    $login = Request::create('/login');
    // Request::create() sends "en-us,en;q=0.5"; this guest sends nothing.
    $login->headers->remove('Accept-Language');
    $guest = UserRequest::as($alice, $login)->anonymous();

    [$asAlice, $asGuest] = OneWorker::run([
        UserRequest::as($alice, Request::create('/dashboard'), 'Alice'),
        $guest,
    ], aliceAndBob());

    expect($asAlice->getContent())->toContain('<html lang="de"')->toContain('Hauptnavigation');
    expect($asGuest->getStatusCode())->toBe(200)
        ->and($asGuest->getContent())
        ->toContain('<html lang="en"')
        ->toContain('Main navigation')
        ->not->toContain('Hauptnavigation');
});
