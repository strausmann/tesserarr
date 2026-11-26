<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Account;

use App\Audit\Actor;
use App\Audit\AuditPresenter;
use App\Http\Controllers\Controller;
use App\Http\SelfService;
use App\Identity\Identity;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\AccountEmailState;
use App\Privacy\EmailAlreadyClaimed;
use App\Privacy\EmailClaimThrottled;
use App\Privacy\EmailConfirmation;
use App\Privacy\EmailEntryDisabled;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "My account" (Account.dc.html, pre-flight K22): the frame page of the own
 * account. Task 8a puts in the account address, the addresses the sign-in
 * providers reported, the display language and the own activity; tokens (9),
 * the PGP key (10a), passkeys and TOTP (11, 12) and the sign-in methods (16)
 * add their sections.
 *
 * The account sees its own addresses in plain text, nobody else's. Until the
 * confirmation with a fresh second factor exists (task 11, pre-flight K14),
 * an account enters a first address only (EmailConfirmation refuses the
 * change of a confirmed one on every path); changing it comes with task 11.
 */
#[SelfService]
final class AccountController extends Controller
{
    public const RECENT_ACTIVITY = 5;

    public const ACTIVITY_PAGE = 50;

    public function __construct(
        private readonly EmailConfirmation $confirmation,
        private readonly AccountEmailPolicy $policy,
        private readonly AuditPresenter $presenter,
    ) {}

    public function show(#[CurrentUser] User $user): Response
    {
        $identities = $user->identities()->orderBy('id')->get();
        $email = AccountEmailState::of($user, $this->policy);

        return Inertia::render('account/Index', [
            'account' => ['username' => $user->username, 'display_name' => $user->display_name],
            'email' => $email,
            'identities' => $identities->map(fn (Identity $identity) => [
                'id' => $identity->id,
                'method' => $identity->method,
                'provider_key' => $identity->provider_key,
                'email' => $identity->email,
            ])->values()->all(),
            // The provider's address as a proposal while the account has none;
            // taking it over is a request like any other (unique, confirmed by link).
            'suggestion' => $email['confirmed'] === null && $email['pending'] === null
                ? $identities->pluck('email')->filter()->first()
                : null,
            'security' => [
                'passkeys' => $user->passkeys()->count(),
                'two_factor' => $user->two_factor_confirmed_at !== null,
            ],
            'activity' => $this->presenter->present(
                AuditPresenter::involving($user)->orderByDesc('id')->limit(self::RECENT_ACTIVITY)->get(),
            ),
        ]);
    }

    public function activity(#[CurrentUser] User $user): Response
    {
        return Inertia::render('account/Activity', [
            'entries' => $this->presenter->present(
                AuditPresenter::involving($user)->orderByDesc('id')->limit(self::ACTIVITY_PAGE)->get(),
            ),
        ]);
    }

    public function updateEmail(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $valid = $request->validate(['email' => ['required', 'string', 'max:254', 'email:rfc,strict']]);

        return $this->request($user, (string) $valid['email']);
    }

    public function resendEmail(#[CurrentUser] User $user): RedirectResponse
    {
        $pending = AccountEmailState::of($user, $this->policy)['pending'];
        if ($pending === null) {
            return back()->withErrors(['email' => __('account.email.nothing_pending')]);
        }

        return $this->request($user, $pending);
    }

    private function request(User $user, string $email): RedirectResponse
    {
        try {
            $this->confirmation->request($user, $email, Actor::user($user));
        } catch (EmailEntryDisabled|EmailAlreadyClaimed|EmailClaimThrottled $e) {
            return back()->withErrors(['email' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => __('account.email.link_sent')]);

        return back();
    }
}
