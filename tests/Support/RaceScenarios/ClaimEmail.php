<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Audit\Actor;
use App\Models\User;
use App\Privacy\EmailAlreadyClaimed;
use App\Privacy\EmailConfirmation;

/**
 * The account named in the first argument asks for the address in the
 * second (EmailConfirmation::request), which stops at the barrier point
 * `email.claim` after it freed stale reservations and before it claims the
 * address. Reports whether the claim won.
 */
final class ClaimEmail implements RaceScenario
{
    public function __construct(private readonly EmailConfirmation $confirmation) {}

    public function run(array $arguments): mixed
    {
        $user = User::query()->where('username', $arguments[0])->firstOrFail();

        try {
            $this->confirmation->request($user, $arguments[1], Actor::user($user));
        } catch (EmailAlreadyClaimed) {
            return ['claimed' => false];
        }

        return ['claimed' => true];
    }
}
