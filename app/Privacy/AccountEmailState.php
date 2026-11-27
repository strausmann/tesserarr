<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use App\Models\User;

/**
 * The account address of one account as a page shows it (My account, the
 * user detail page): the confirmed address, the one waiting for its link and
 * until when, and whether an address can be entered now (and if not, why).
 * Only for whom may see the address: the account itself, or `users.view`.
 */
final readonly class AccountEmailState
{
    /**
     * @return array{confirmed: string|null, pending: string|null, pending_until: string|null, entry: array{allowed: bool, reason: string|null}}
     */
    public static function of(User $user, AccountEmailPolicy $policy): array
    {
        $until = $user->pending_email_reserved_until;
        $held = $until !== null && $until->isFuture() ? $until : null;
        $pending = $held === null ? null : $user->pending_email;
        $pendingUntil = $held === null || $pending === null ? null : $held->toIso8601String();
        $reason = $policy->refusal();

        return [
            'confirmed' => $user->confirmedEmail(),
            'pending' => $pending,
            'pending_until' => $pendingUntil,
            'entry' => ['allowed' => $reason === null, 'reason' => $reason],
        ];
    }
}
