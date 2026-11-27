<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Mfa;

/**
 * What a sign-in method reported about a second factor of this sign-in
 * (Spec 9.3): proven (for OIDC: `amr` of the ID token names one), not proven,
 * or stale (the proof is older than max_age allows). Evaluated from task 12.
 */
enum MfaEvidence
{
    case Proven;
    case NotProven;
    case Stale;
}
