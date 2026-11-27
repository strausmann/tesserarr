<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Mfa;

/**
 * How far a sign-in method's own second factor counts (Spec 9.3), set per
 * method: always ask for an own factor (the default), trust the upstream
 * sign-in, or trust it when this sign-in proved a factor (MfaEvidence).
 * Evaluated from task 12.
 */
enum UpstreamMfaTrust: string
{
    case RequireOwnFactor = 'require_own';
    case TrustUpstream = 'trust';
    case TrustIfProven = 'trust_if_proven';

    public const DEFAULT = self::RequireOwnFactor;
}
