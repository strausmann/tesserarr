<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Http\Controllers\Controller;
use App\Privacy\EmailConfirmation;
use App\Privacy\InvalidConfirmationLink;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The link of the confirmation mail (task 8a), public: it works without a
 * sign-in, the token is the proof. Opening the link only shows a page with a
 * button; the token is used when the button is pressed (POST), so a mail
 * scanner that fetches every link does not use it up.
 */
final class EmailConfirmationController extends Controller
{
    public function __construct(private readonly EmailConfirmation $confirmation) {}

    public function show(string $token): Response
    {
        return Inertia::render('auth/ConfirmEmail', ['token' => $token]);
    }

    public function confirm(Request $request, string $token): RedirectResponse
    {
        try {
            $user = $this->confirmation->confirm($token);
        } catch (InvalidConfirmationLink $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.email.just_confirmed')]);

        return to_route($request->user()?->getAuthIdentifier() === $user->getKey() ? 'account' : 'login');
    }
}
