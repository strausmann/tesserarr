<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\SelfService;
use App\Http\Ui\Audience;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/** "/": the sign-in for a guest, the home of the audience otherwise. */
#[SelfService]
final class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        return Audience::of($user) === Audience::Operator
            ? redirect()->route('dashboard')
            : redirect()->route('friend.home');
    }
}
