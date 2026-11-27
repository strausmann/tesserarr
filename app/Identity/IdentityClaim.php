<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

use App\Mfa\MfaEvidence;

/**
 * Who a sign-in method says the person is: the method, which provider of it
 * (the slug of an OIDC provider, "plex", the name of a header source), the
 * subject the provider knows them by, and what it reported besides. $email
 * is the provider's address: it goes to the identity, never to the account
 * address (users.email).
 */
final readonly class IdentityClaim
{
    public function __construct(
        public LoginMethod $method,
        public string $providerKey,
        public string $subject,
        public ?string $username = null,
        public ?string $email = null,
        public ?string $displayName = null,
        public MfaEvidence $mfaEvidence = MfaEvidence::NotProven,
    ) {}
}
