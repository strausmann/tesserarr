<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Worker;

use Closure;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Support\Facades\Facade;
use Laravel\Octane\ApplicationFactory;
use Laravel\Octane\Events\RequestReceived;
use Laravel\Octane\Testing\Fakes\FakeClient;
use Laravel\Octane\Testing\Fakes\FakeWorker;
use Pest\TestSuite;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;

/**
 * Sends requests one after the other through ONE booted Octane worker, the
 * way the worker serves them in production: the application is booted once,
 * the listeners of config/octane.php reset the framework state, every request
 * runs in a clone of the booted application, and nothing is booted in
 * between.
 *
 * It wraps Octane's FakeWorker and FakeClient, which Octane tests itself with
 * but does not document (laravel/octane src/Testing/Fakes). A change there
 * breaks this class and nothing else.
 *
 * The requests are signed in without a session: a listener after Octane's
 * own RequestReceived listeners puts the user of the UserRequest on the web
 * guard of the request's sandbox. Login, sessions and cookies are not part of
 * what this checks; what the application keeps between requests is.
 *
 * Booting a second application changes process-wide state (the container
 * instance, the facade root, the error handlers). run() restores what it can;
 * the Worker test suite runs last for the rest.
 */
final class OneWorker
{
    /**
     * @param  list<UserRequest>  $requests
     * @param  (Closure(Application): void)|null  $boot  runs once on the booted application, before the first request
     * @return list<Response> the responses, in the order of the requests
     */
    public static function run(array $requests, ?Closure $boot = null): array
    {
        $client = new FakeClient(array_map(fn (UserRequest $request) => $request->request, $requests));
        $worker = new FakeWorker(new ApplicationFactory(dirname(__DIR__, 3)), $client);

        try {
            $worker->boot();
            $app = $worker->application();

            $app->make('events')->listen(RequestReceived::class, static function (RequestReceived $event): void {
                $user = $event->request->attributes->get(UserRequest::ATTRIBUTE);
                if ($user !== null) {
                    $event->sandbox->make('auth')->guard('web')->setUser($user);
                }
            });

            if ($boot !== null) {
                $boot($app);
            }

            $worker->run();
        } finally {
            self::releaseProcessState();
        }

        Assert::assertSame([], $client->errors, 'A request failed inside the worker.');
        Assert::assertCount(count($requests), $client->responses, 'Not every request got a response.');

        /** @var list<Response> */
        return $client->responses;
    }

    /**
     * Hands the process back without the worker's application: the next test
     * boots its own.
     */
    private static function releaseProcessState(): void
    {
        // Puts PHPUnit's error handler back in place of the one the worker's boot installed.
        HandleExceptions::flushState(TestSuite::getInstance()->test);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication(null);
        Container::setInstance(null);
    }
}
