<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Invitations;

use App\Http\Controllers\Controller;
use App\Invitations\InvitationKind;
use App\Invitations\InvitationService;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The page behind the link of an invitation (Invite-Phone.dc.html). Public:
 * the token is the proof. It travels in the fragment of the link
 * (/invite#<token>), which the browser never sends, so no access log,
 * referer or session (previous URL) sees it. The page posts it in the body
 * to see who invites and until when; nothing is used up here.
 *
 * The redemption itself belongs to the sign-in method the person chooses:
 * OIDC, Plex and the header (tasks 13 to 15) call InvitationService::redeem
 * with the identity they bring, password and passkey (task 11) reserve and
 * complete. Each of those tasks adds its method to `methods`.
 */
final class RedeemController extends Controller
{
    public function __construct(private readonly InvitationService $invitations) {}

    public function show(): Response
    {
        return Inertia::render('auth/Redeem', ['invitation' => null, 'methods' => []]);
    }

    /** What the invitation is, or one message for every reason it cannot be used. */
    public function check(Request $request): Response|RedirectResponse
    {
        $valid = $request->validate(['token' => ['required', 'string', 'max:128']]);
        $invitation = $this->invitations->find((string) $valid['token']);

        if ($invitation === null) {
            return back()->withErrors(['token' => __('invitations.redeem.not_redeemable')]);
        }

        $by = $invitation->created_by_actor;
        $inviter = isset($by['id']) && in_array($by['type'], ['user', 'token'], true)
            ? User::query()->find($by['id'])?->shownName()
            : null;

        return Inertia::render('auth/Redeem', [
            'invitation' => [
                'kind' => $invitation->kind->value,
                'inviter' => $inviter,
                'expires_at' => $invitation->expires_at->toIso8601String(),
                // A recovery invitation is for one account: its owner sees which.
                'account' => $invitation->kind === InvitationKind::Recovery ? $invitation->forUser?->username : null,
            ],
            'methods' => [],
        ]);
    }
}
