<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\SingletonAttributeRequestStateRule;

/**
 * @extends WorkerRuleTestCase<SingletonAttributeRequestStateRule>
 */
final class SingletonAttributeRequestStateRuleTest extends WorkerRuleTestCase
{
    protected function getRule(): Rule
    {
        return new SingletonAttributeRequestStateRule($this->createReflectionProvider());
    }

    public function test_it_reports_singleton_attributes_on_classes_that_take_request_state(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/Singletons.php'], [
            ['Singleton App\\Fixtures\\Singletons\AttributeSingletonWithSession is built from request state (constructor parameter $session: Illuminate\Session\Store). The value of the first request stays for every later request in worker mode. Bind it with scoped() or read the value per call, or justify it with a "worker-safe: <reason>" comment.', 50],
        ]);
    }
}
