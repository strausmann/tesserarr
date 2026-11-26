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
 * sign-in, the token is the proof. The token is in the fragment of the link,
 * so the GET never carries it (logs, referers, the session's previous URL).
 * The page posts it in the body when the button is pressed, so a mail
 * scanner that fetches every link does not use it up.
 */
final class EmailConfirmationController extends Controller
{
    public function __construct(private readonly EmailConfirmation $confirmation) {}

    /** The page reads the token from the fragment of the link; the server never sees it here. */
    public function show(): Response
    {
        return Inertia::render('auth/ConfirmEmail');
    }

    public function confirm(Request $request): RedirectResponse
    {
        $valid = $request->validate(['token' => ['required', 'string', 'max:128']]);

        try {
            $user = $this->confirmation->confirm((string) $valid['token']);
        } catch (InvalidConfirmationLink $e) {
            return back()->withErrors(['token' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.email.just_confirmed')]);

        return to_route($request->user()?->getAuthIdentifier() === $user->getKey() ? 'account' : 'login');
    }
}
