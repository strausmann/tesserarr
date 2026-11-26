<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\SelfService;
use Inertia\Inertia;
use Inertia\Response;

/** The own appearance settings (light, dark, system). */
#[SelfService]
final class AppearanceController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('settings/Appearance');
    }
}
