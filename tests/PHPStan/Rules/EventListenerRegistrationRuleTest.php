<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\EventListenerRegistrationRule;

/**
 * @extends WorkerRuleTestCase<EventListenerRegistrationRule>
 */
final class EventListenerRegistrationRuleTest extends WorkerRuleTestCase
{
    private const MESSAGE = 'Event listener registered with %s in %s: Octane does not reset the event dispatcher, so every call adds a listener that stays for every later request. Register listeners in register() or boot() of a class in App\Providers, or justify it with a @phpstan-ignore and a reason.';

    protected function getRule(): Rule
    {
        return new EventListenerRegistrationRule(['App\\'], []);
    }

    public function test_it_reports_listeners_registered_outside_a_service_provider(): void
    {
        $handle = 'App\Fixtures\Events\RemembersTheRequest::handle()';

        $this->analyse([__DIR__.'/../Fixtures/Events/Listeners.php'], [
            [sprintf(self::MESSAGE, 'Illuminate\Support\Facades\Event::listen()', $handle), 27],
            [sprintf(self::MESSAGE, 'Illuminate\Support\Facades\Event::subscribe()', $handle), 28],
            [sprintf(self::MESSAGE, 'listen()', $handle), 29],
            [sprintf(self::MESSAGE, 'listen()', $handle), 30],
        ]);
    }

    public function test_it_lets_register_and_boot_of_a_provider_register_listeners(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/Providers/EventServiceProvider.php'], [
            [sprintf(self::MESSAGE, 'Illuminate\Support\Facades\Event::listen()', 'App\Providers\Fixture\EventServiceProvider::later()'), 33],
        ]);
    }
}
