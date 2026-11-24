<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Testing;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Runtime\E2eImage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Signs a browser test in as the fixture account of a role.
 *
 * Part of the e2e image only (see App\Runtime\E2eImage). The marker is checked
 * again here, so the route does nothing even if it were registered by mistake.
 */
final class E2eLoginController extends Controller
{
    /** Roles with a fixture account. Real roles arrive with the authorisation model. */
    public const ROLES = ['friend', 'operator'];

    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(E2eImage::enabled() && in_array($role, self::ROLES, true), 404);

        $user = User::query()->firstOrCreate(
            ['email' => "e2e-{$role}@example.test"],
            [
                'name' => 'E2E '.ucfirst($role),
                'password' => Str::password(32),
                'email_verified_at' => now(),
            ],
        );

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }
}
