<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Testing;

use App\Authorization\Roles;
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
    /** The fixture accounts: the friend has the role "user", the operator "operator". */
    public const ROLES = ['friend' => Roles::USER, 'operator' => Roles::OPERATOR];

    public function __invoke(Request $request, string $role): RedirectResponse
    {
        abort_unless(E2eImage::enabled() && array_key_exists($role, self::ROLES), 404);

        $user = User::query()->firstOrCreate(
            ['username' => "e2e-{$role}"],
            [
                'display_name' => 'E2E '.ucfirst($role),
                'password' => Str::password(32),
            ],
        );
        $user->syncRoles([self::ROLES[$role]]);

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route($role === 'friend' ? 'friend.home' : 'dashboard');
    }
}
