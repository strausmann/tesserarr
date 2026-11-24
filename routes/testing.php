<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// Browser tests only. Loaded by App\Runtime\E2eImage::registerRoutes in the
// e2e image; the published image does not contain this file.

use App\Testing\E2eLoginController;
use Illuminate\Support\Facades\Route;

Route::get('_testing/login/{role}', E2eLoginController::class)->name('testing.login');
