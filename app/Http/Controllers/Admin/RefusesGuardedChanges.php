<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Authorization\Permission;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * One answer for a change the guard rules refused: JSON with an `error` code
 * (and the refused permissions) for API-style requests, otherwise back to the
 * page with the message on the field, as Inertia forms expect.
 */
trait RefusesGuardedChanges
{
    /**
     * @param  list<Permission>  $permissions
     */
    private function refuse(Request $request, string $error, int $status, string $field, string $message, array $permissions = []): JsonResponse|RedirectResponse
    {
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            $body = ['error' => $error, 'message' => $message];
            if ($permissions !== []) {
                $body['permissions'] = Permission::values($permissions);
            }

            return response()->json($body, $status);
        }

        return back()->withErrors([$field => $message]);
    }
}
