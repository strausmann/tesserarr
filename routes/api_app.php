<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

/*
| App API under /api/v1/app for the web interface (middleware group
| "session-api": session cookie, X-Requested-With: tesserarr, CSRF token),
| central role only. A bearer token is never read here (ADR-0007). A route
| that changes something carries ->defaults('audited', true).
*/

use App\Http\Controllers\Api\App\InvitationController;
use App\Http\Controllers\Api\App\TokenController;
use App\Http\Controllers\Api\App\UserController;
use App\Http\Controllers\Api\MeController;
use App\Http\Controllers\Ui\CollapsedSectionController;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;

Route::get('me', [MeController::class, 'app'])->name('me');
Route::put('me/ui/collapsed', CollapsedSectionController::class)->name('me.ui.collapsed');

Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:users.view')->name('users.show');

// API tokens (task 9). Issuing needs a fresh password confirmation (K13).
Route::post('tokens', [TokenController::class, 'store'])
    ->middleware(['can:tokens.manage_own', RequirePassword::class, 'throttle:20,1'])
    ->defaults('audited', true)->name('tokens.store');
// Revoking an own token is self-service: possible also after tokens.manage_own was taken away.
Route::delete('tokens/{token}', [TokenController::class, 'destroy'])->whereNumber('token')
    ->defaults('audited', true)->name('tokens.destroy');
Route::delete('admin/tokens/{token}', [TokenController::class, 'destroyAny'])->whereNumber('token')
    ->middleware('can:tokens.manage_all')->defaults('audited', true)->name('admin.tokens.destroy');

// Invitations (task 10): the dialog of the user list issues access
// invitations, the user detail a recovery invitation (`user`, which also
// needs users.manage and every permission of that account).
Route::post('invitations', [InvitationController::class, 'store'])
    ->middleware(['can:invitations.create', 'throttle:30,1'])->defaults('audited', true)->name('invitations.store');
Route::delete('invitations/{invitation}', [InvitationController::class, 'destroy'])->whereNumber('invitation')
    ->middleware('can:invitations.revoke')->defaults('audited', true)->name('invitations.destroy');
