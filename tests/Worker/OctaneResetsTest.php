<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Tests\Support\Worker\OneWorker;
use Tests\Support\Worker\UserRequest;

// What Octane does NOT reset between requests, shown through one worker. These
// are the facts behind EventListenerRegistrationRule (tests/PHPStan/): a
// listener registered during a request stays on the dispatcher for every
// later request.

it('keeps listeners that a request registered for every later request', function () {
    $register = function (Application $app): void {
        $app->make('router')->get('/_worker/listen', function () {
            Event::listen('tesserarr.worker.test', fn () => null);

            return (string) count(Event::getListeners('tesserarr.worker.test'));
        });
    };
    $request = fn () => UserRequest::as(workerUser('Alice'), Request::create('/_worker/listen'));

    $responses = OneWorker::run([$request(), $request(), $request()], $register);

    expect(array_map(fn ($response) => $response->getContent(), $responses))->toBe(['1', '2', '3']);
});
