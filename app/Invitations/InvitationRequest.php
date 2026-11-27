<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use App\Settings\SettingKey;
use Carbon\CarbonInterval;
use Illuminate\Http\Request;

/**
 * The fields of a new invitation from the interface and the token API:
 * `days` (1 to the maximum of the general settings, the instance default
 * when missing) and `note` (a label, at most 100 characters).
 */
final class InvitationRequest
{
    /** @return array{0: CarbonInterval|null, 1: string|null} */
    public static function read(Request $request): array
    {
        $valid = $request->validate([
            'days' => ['nullable', 'integer', 'between:1,'.SettingKey::INVITATION_DAYS_MAX],
            'note' => ['nullable', 'string', 'max:'.InvitationService::NOTE_MAX, 'not_regex:/\p{C}/u'],
        ]);

        $days = $valid['days'] ?? null;
        $note = $valid['note'] ?? null;

        return [$days === null ? null : CarbonInterval::days((int) $days), is_string($note) ? $note : null];
    }
}
