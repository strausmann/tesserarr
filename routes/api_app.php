<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
| App API under /api/v1/app for the web interface (session, "web" group),
| central role only. Routes are added by later steps.
*/

use App\Http\Controllers\Ui\CollapsedSectionController;
use App\Http\Controllers\Ui\VersionController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth')->group(function () {
    // ui.collapsed: whether the user collapsed a section (Collapsible.svelte).
    Route::put('me/ui/collapsed', CollapsedSectionController::class)->name('me.ui.collapsed');
    // The running version, asked by an open tab now and then (lib/updates.svelte.ts).
    Route::get('version', VersionController::class)->name('version');
});
