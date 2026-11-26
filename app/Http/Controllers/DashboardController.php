<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\SelfService;
use App\Http\Ui\Audience;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The operator's start page (Dashboard.dc.html). It needs no permission of
 * its own: each of its sections is filled by the task that builds it and
 * asks for that section's permission. A friend (no administrative
 * permission) is sent to "/", which knows the right home.
 */
#[SelfService]
final class DashboardController extends Controller
{
    public function __invoke(#[CurrentUser] User $user): Response|RedirectResponse
    {
        if (Audience::of($user) !== Audience::Operator) {
            return redirect()->route('home');
        }

        return Inertia::render('Dashboard');
    }
}
