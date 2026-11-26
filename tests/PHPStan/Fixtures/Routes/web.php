<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// Fixture: a route file has no namespace, but it runs inside a request.

use Illuminate\Support\Facades\Route;

Route::get('/me', function () {
    static $user;
    $user ??= request()->user();

    return $user;
});
