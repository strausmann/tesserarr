<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\NoMutablePublicPropertyRule;

/**
 * @extends WorkerRuleTestCase<NoMutablePublicPropertyRule>
 */
final class NoMutablePublicPropertyRuleTest extends WorkerRuleTestCase
{
    protected function getRule(): Rule
    {
        return new NoMutablePublicPropertyRule(['App\\'], [Model::class, ShouldQueue::class]);
    }

    public function test_it_reports_public_properties_that_anyone_can_write(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/PublicState.php'], [
            ['Public property App\Fixtures\PublicState\InvitationService::$currentUser can be written by any caller and outlives the request when the object is shared. Make it readonly or private, or justify it with a "worker-safe: <reason>" comment.', 18],
            ['Public property App\Fixtures\PublicState\InvitationService::$tenant can be written by any caller and outlives the request when the object is shared. Make it readonly or private, or justify it with a "worker-safe: <reason>" comment.', 20],
        ]);
    }

    public function test_it_ignores_code_outside_the_checked_namespaces(): void
    {
        $this->analyse([__DIR__.'/../Fixtures/OutsideApp/StaticElsewhere.php'], []);
    }
}
