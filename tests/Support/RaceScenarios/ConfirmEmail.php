<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\RaceScenarios;

use App\Privacy\EmailConfirmation;
use App\Privacy\InvalidConfirmationLink;

/** Opens the confirmation link whose token is the first argument; reports the outcome. */
final class ConfirmEmail implements RaceScenario
{
    public function __construct(private readonly EmailConfirmation $confirmation) {}

    public function run(array $arguments): mixed
    {
        try {
            $this->confirmation->confirm($arguments[0]);
        } catch (InvalidConfirmationLink) {
            return ['confirmed' => false];
        }

        return ['confirmed' => true];
    }
}
