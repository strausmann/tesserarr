<?php

declare(strict_types=1);

use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\HomeController;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Scalar\Controllers\ScalarController;

// Every route is public, self-service (App\Http\SelfService) or names its
// permission with `can:`; tests/Feature/Security/AuthorizerCallersTest.php
// enforces it.

// Sends a guest to the sign-in and a signed-in account to its home.
Route::get('/', HomeController::class)->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    // The operator's start page; a friend is sent on to /shared.
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // The friend's home: what is shared with them (Friend-Home.dc.html). The
    // shares and tokens in it arrive with tasks 21a and 9.
    Route::inertia('shared', 'friend/Home')->middleware('can:catalog.browse')->name('friend.home');

    // Administration (Users.dc.html, Roles.dc.html, Audit.dc.html).
    Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::put('users/{user}/role', [UserController::class, 'updateRole'])->middleware('can:users.manage')->name('users.role');
    Route::post('users/{user}/lock', [UserController::class, 'lock'])->middleware('can:users.manage')->name('users.lock');
    Route::delete('users/{user}/lock', [UserController::class, 'unlock'])->middleware('can:users.manage')->name('users.unlock');

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.manage')->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->name('roles.destroy');

    Route::get('audit', AuditLogController::class)->middleware('can:audit.view')->name('audit.index');

    // General settings (Settings-General.dc.html).
    Route::get('settings/general', [GeneralSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.general.edit');
    Route::put('settings/general', [GeneralSettingsController::class, 'update'])->middleware('can:settings.manage')->name('settings.general.update');
});

// The interactive API reference (Scalar) and the OpenAPI document it reads,
// generated from the running code. For accounts that may hold API tokens;
// config/scramble.php puts the document route behind the same check.
Route::get('docs/api', ScalarController::class)->middleware(['auth', 'can:tokens.manage_own'])->name('api-reference');
Scramble::registerJsonSpecificationRoute('docs/api/openapi.json')->name('api-reference.document');

require __DIR__.'/settings.php';
