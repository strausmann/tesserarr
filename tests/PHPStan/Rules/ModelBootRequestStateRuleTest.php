<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\PHPStan\Rules;

use PHPStan\Rules\Rule;
use Tests\PHPStan\Rules\Worker\ModelBootRequestStateRule;

/**
 * @extends WorkerRuleTestCase<ModelBootRequestStateRule>
 */
final class ModelBootRequestStateRuleTest extends WorkerRuleTestCase
{
    private const BOOT = '%s reads request state (%s) in %s(), which runs once per worker: the value of the first request stays in every scope and listener it builds. Read it inside the closure, or justify it with a @phpstan-ignore and a reason.';

    private const SCOPE = 'Scope %s reads request state (%s) in its constructor, and a scope is built once in booted(). Read it in apply(), or justify it with a @phpstan-ignore and a reason.';

    protected function getRule(): Rule
    {
        return new ModelBootRequestStateRule;
    }

    public function test_it_reports_request_state_read_while_a_model_boots(): void
    {
        $share = 'App\Fixtures\Models\Share';

        $this->analyse([__DIR__.'/../Fixtures/Models/Shares.php'], [
            [sprintf(self::BOOT, 'Model '.$share, 'session()', 'bootBelongsToTenant'), 24],
            [sprintf(self::BOOT, 'Model '.$share, 'auth()', 'booted'), 35],
            [sprintf(self::BOOT, 'Model '.$share, 'Illuminate\Support\Facades\Auth::id()', 'booted'), 38],
            [sprintf(self::BOOT, 'Model '.$share, 'Illuminate\Support\Facades\Request::ip()', 'boot'), 47],
            [sprintf(self::BOOT, 'Model '.$share, 'Illuminate\Support\Facades\Session::get()', 'boot'), 48],
            [sprintf(self::SCOPE, 'App\Fixtures\Models\OwnerScope', 'auth()'), 63],
        ]);
    }
}
