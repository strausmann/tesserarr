<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Api\App;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;

/**
 * One account for the interface (/api/v1/app/users/{user}, `users.view`).
 * The fields are listed one by one, never the serialised model: the
 * confirmed account address is in the answer because whoever may view users
 * may see it (task 8a); the address waiting for confirmation, the blind
 * indexes and the PGP key are not.
 */
final class UserController extends Controller
{
    public function show(User $user): JsonResponse
    {
        return response()->json([
            'id' => $user->id,
            'username' => $user->username,
            'display_name' => $user->display_name,
            'email' => $user->confirmedEmail(),
            'email_pending' => $user->pending_email_reserved_until?->isFuture() ?? false,
            'locked' => $user->locked_at !== null,
        ]);
    }
}
