<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\PropertyWriteCollector;
use Tests\PHPStan\Rules\Worker\SingletonAttributeCollector;
use Tests\PHPStan\Rules\Worker\SingletonBindingCollector;
use Tests\PHPStan\Rules\Worker\SingletonStateRule;

/**
 * @extends WorkerRuleTestCase<SingletonStateRule>
 */
final class SingletonStateRuleTest extends WorkerRuleTestCase
{
    private const MESSAGE = 'Singleton %s writes $%s in %s(), so what one request leaves there stays for every later request in worker mode. Keep per-request state out of the singleton (scoped() or a value passed per call), or justify it with a @phpstan-ignore and a reason.';

    protected function getRule(): Rule
    {
        return new SingletonStateRule($this->createReflectionProvider());
    }

    protected function getCollectors(): array
    {
        return [new SingletonBindingCollector, new SingletonAttributeCollector, new PropertyWriteCollector];
    }

    public function test_it_reports_singletons_that_write_their_properties_after_construction(): void
    {
        $ns = 'App\Fixtures\SingletonState\\';

        $this->analyse([__DIR__.'/../Fixtures/SingletonState/Services.php'], [
            [sprintf(self::MESSAGE, $ns.'MemoGreeter', 'seen', $ns.'MemoGreeter::greet'), 30],
            [sprintf(self::MESSAGE, $ns.'MemoGreeter', 'name', $ns.'MemoGreeter::greet'), 32],
            [sprintf(self::MESSAGE, $ns.'ClosureMemo', 'items', $ns.'ClosureMemo::add'), 70],
            [sprintf(self::MESSAGE, $ns.'AttributeCounter', 'hits', $ns.'AttributeCounter::hit'), 81],
            [sprintf(self::MESSAGE, $ns.'InheritsCache', 'cache', $ns.'BaseCache::put'), 102],
        ]);
    }
}
