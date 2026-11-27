<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

use App\Models\User;

/**
 * Binds an identity (IdentityClaim) to an account. The one place that
 * creates rows in identities; tasks 13 to 15 use it for their sign-ins,
 * task 16 adds the account holder's own linking.
 *
 * The provider's address goes to identities.email, never to the account
 * address. The unique index on (method, provider_key, subject) decides when
 * two requests bind the same identity at once: the second fails with
 * UniqueConstraintViolationException, which the caller handles.
 */
final class IdentityLinker
{
    /** $why is named in the caller's audit entry (invitation.redeem, and the sign-ins of tasks 13 to 16). */
    public function attach(User $user, IdentityClaim $claim, AttachReason $why): Identity
    {
        return Identity::query()->create([
            'user_id' => $user->getKey(),
            'method' => $claim->method->value,
            'provider_key' => $claim->providerKey,
            'subject' => $claim->subject,
            'email' => $claim->email,
        ]);
    }

    /** The identity that $claim names, when one exists. */
    public function find(IdentityClaim $claim): ?Identity
    {
        return Identity::query()
            ->where('method', $claim->method->value)
            ->where('provider_key', $claim->providerKey)
            ->where('subject', $claim->subject)
            ->first();
    }
}
