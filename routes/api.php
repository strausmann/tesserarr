<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
| Token API under /api/v1 (middleware group "token-api"), central role only.
| A bearer token and nothing else signs a request in (ADR-0007). Every route
| names its permission with `can:` (exported as the token ability) or is
| self-service; a route that changes something carries
| ->defaults('audited', true) (tests/Feature/Security/RouteInventoryTest.php).
*/

use App\Http\Controllers\Api\MeController;
use Illuminate\Support\Facades\Route;

Route::get('me', [MeController::class, 'token'])->name('me');
