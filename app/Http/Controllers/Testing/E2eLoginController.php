<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Http\Controllers\Testing;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;

/**
 * Signs a browser test in as the fixture account of a role.
 *
 * Exists only in the e2e image: APP_ENV=testing and TESSERARR_E2E_LOGIN=true,
 * both set by the `e2e` stage of docker/Dockerfile and by no other stage.
 * Anywhere else, and for an unknown role, the route answers 404 as if it did
 * not exist.
 */
final class E2eLoginController extends Controller
{
    /** Roles with a fixture account. Real roles arrive with the authorisation model. */
    public const ROLES = ['friend', 'operator'];

    public static function enabled(): bool
    {
        return app()->environment('testing') && config('tesserarr.e2e_login') === true;
    }

    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(self::enabled() && in_array($role, self::ROLES, true), 404);

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
