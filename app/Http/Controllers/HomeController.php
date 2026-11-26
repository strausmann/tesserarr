<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Authorization\Permission;
use App\Http\SelfService;
use App\Http\Ui\Audience;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** "/": the sign-in for a guest, the home of the account otherwise. */
#[SelfService]
final class HomeController extends Controller
{
    public function __invoke(Request $request): RedirectResponse|Response
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return redirect()->route('login');
        }

        $granted = Audience::granted($user);

        if (Audience::fromGranted($granted) === Audience::Operator) {
            return redirect()->route('dashboard');
        }

        // A friend's home needs catalog.browse; an account without it (an
        // own role without, or no role yet) gets a page that says so instead
        // of a 403 right after signing in.
        return in_array(Permission::CatalogBrowse, $granted, true)
            ? redirect()->route('friend.home')
            : Inertia::render('NoAccess');
    }
}
