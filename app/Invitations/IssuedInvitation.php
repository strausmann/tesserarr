<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

/**
 * A new invitation and its link. The link carries the token in its fragment
 * (/invite#<token>): the browser never sends it, so no access log, referer
 * or session sees it. It exists only here, to be shown once.
 */
final readonly class IssuedInvitation
{
    public function __construct(
        public Invitation $invitation,
        #[\SensitiveParameter] public string $plainToken,
        public string $url,
    ) {}
}
