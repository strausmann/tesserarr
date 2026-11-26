<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends the session of a locked account on its next request. The Authorizer
 * refuses every permission of a locked account anyway; this also closes the
 * self-service pages and the session itself. The lock is read from the
 * database on every request (User::isBlocked), not from the account object
 * the guard holds, so it applies at once.
 */
final class SignOutLockedAccount
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if ($user instanceof User && $user->isBlocked()) {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'locked'], 401)
                : redirect()->route('login');
        }

        return $next($request);
    }
}
