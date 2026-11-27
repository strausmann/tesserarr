<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * The friend's home (Friend-Home.dc.html, `catalog.browse`): what is shared
 * (task 21a) and the own tokens, by name only. The shares arrive with 21a.
 */
final class FriendHomeController extends Controller
{
    /** How many of the own tokens the home lists; the token page has all. */
    public const TOKENS_SHOWN = 5;

    public function __invoke(#[CurrentUser] User $user): Response
    {
        $tokens = PersonalAccessToken::query()
            ->where('tokenable_type', $user->getMorphClass())
            ->where('tokenable_id', $user->getKey())
            ->orderByDesc('id')
            ->get(['id', 'name', 'expires_at']);

        return Inertia::render('friend/Home', [
            'tokens' => $tokens->take(self::TOKENS_SHOWN)->map(fn (PersonalAccessToken $token) => [
                'id' => (int) $token->getKey(),
                'name' => (string) $token->getAttribute('name'),
                'expires_at' => $token->expires_at?->toIso8601String(),
            ])->values()->all(),
            'tokens_total' => $tokens->count(),
        ]);
    }
}
