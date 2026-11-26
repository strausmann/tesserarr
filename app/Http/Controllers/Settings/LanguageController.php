<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Middleware\SetLocale;
use App\Models\User;
use Illuminate\Container\Attributes\CurrentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The language of the interface in the profile; null hands the choice back to
 * the browser (SetLocale).
 */
final class LanguageController extends Controller
{
    public function __invoke(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $valid = $request->validate([
            'locale' => ['present', 'nullable', 'string', Rule::in(SetLocale::SUPPORTED)],
        ]);

        $user->forceFill(['locale' => $valid['locale']])->save();

        return back();
    }
}
