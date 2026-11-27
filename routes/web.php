<?php

declare(strict_types=1);

use App\Http\Controllers\Account\AccountController;
use App\Http\Controllers\Account\EmailConfirmationController;
use App\Http\Controllers\Admin\AuditLogController;
use App\Http\Controllers\Admin\GeneralSettingsController;
use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\FriendHomeController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\Tokens\TokenPageController;
use Dedoc\Scramble\Scramble;
use Illuminate\Auth\Middleware\RequirePassword;
use Illuminate\Support\Facades\Route;
use Scalar\Controllers\ScalarController;

// Every route is public, self-service (App\Http\SelfService) or names its
// permission with `can:`; tests/Feature/Security/AuthorizerCallersTest.php
// enforces it. A route that changes something carries
// ->defaults('audited', true) and writes an audit entry
// (tests/Feature/Security/RouteInventoryTest.php names the exceptions).

// Sends a guest to the sign-in and a signed-in account to its home.
Route::get('/', HomeController::class)->name('home');

// The link of the confirmation mail (task 8a): public, the token is the proof.
// It travels in the fragment (/email/confirm#<token>), which the browser never
// sends: no access log, no referer and no session (previous URL) sees it.
// Opening the link shows a button; only the POST, with the token in its
// body, uses it.
Route::get('email/confirm', [EmailConfirmationController::class, 'show'])->name('email.confirm.show');
Route::post('email/confirm', [EmailConfirmationController::class, 'confirm'])->middleware('throttle:10,1')->defaults('audited', true)->name('email.confirm');

Route::middleware(['auth', 'verified'])->group(function () {
    // The operator's start page; a friend is sent on to /shared.
    Route::get('dashboard', DashboardController::class)->name('dashboard');
    // The friend's home: what is shared with them (Friend-Home.dc.html). The
    // shares in it arrive with task 21a; the own tokens are listed by name.
    Route::get('shared', FriendHomeController::class)->middleware('can:catalog.browse')->name('friend.home');

    // My account (Account.dc.html, task 8a): self-service, no permission.
    Route::get('account', [AccountController::class, 'show'])->name('account');
    Route::get('account/activity', [AccountController::class, 'activity'])->name('account.activity');
    Route::put('account/email', [AccountController::class, 'updateEmail'])->defaults('audited', true)->name('account.email');
    Route::post('account/email/resend', [AccountController::class, 'resendEmail'])->defaults('audited', true)->name('account.email.resend');

    // Administration (Users.dc.html, User-Detail.dc.html, Roles.dc.html, Audit.dc.html).
    Route::get('users', [UserController::class, 'index'])->middleware('can:users.view')->name('users.index');
    Route::get('users/{user}', [UserController::class, 'show'])->middleware('can:users.view')->name('users.show');
    Route::put('users/{user}/email', [UserController::class, 'updateEmail'])->middleware('can:users.manage')->defaults('audited', true)->name('users.email');
    Route::delete('users/{user}/email/pending', [UserController::class, 'releaseEmail'])->middleware('can:users.manage')->defaults('audited', true)->name('users.email.release');
    Route::put('users/{user}/role', [UserController::class, 'updateRole'])->middleware('can:users.manage')->defaults('audited', true)->name('users.role');
    Route::post('users/{user}/lock', [UserController::class, 'lock'])->middleware('can:users.manage')->defaults('audited', true)->name('users.lock');
    Route::delete('users/{user}/lock', [UserController::class, 'unlock'])->middleware('can:users.manage')->defaults('audited', true)->name('users.unlock');

    Route::get('roles', [RoleController::class, 'index'])->middleware('can:roles.manage')->name('roles.index');
    Route::post('roles', [RoleController::class, 'store'])->middleware('can:roles.manage')->defaults('audited', true)->name('roles.store');
    Route::put('roles/{role}', [RoleController::class, 'update'])->middleware('can:roles.manage')->defaults('audited', true)->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->middleware('can:roles.manage')->defaults('audited', true)->name('roles.destroy');

    // API tokens (Tokens.dc.html, task 9): the own, or every token with
    // tokens.manage_all. Behind a fresh password confirmation, like the
    // issuing itself (K13). Issuing and revoking go through /api/v1/app.
    Route::get('tokens', TokenPageController::class)->middleware(['can:tokens.manage_own', RequirePassword::class])->name('tokens.index');

    Route::get('audit', AuditLogController::class)->middleware('can:audit.view')->name('audit.index');

    // General settings (Settings-General.dc.html).
    Route::get('settings/general', [GeneralSettingsController::class, 'edit'])->middleware('can:settings.manage')->name('settings.general.edit');
    Route::put('settings/general', [GeneralSettingsController::class, 'update'])->middleware('can:settings.manage')->defaults('audited', true)->name('settings.general.update');
});

// The interactive API reference (Scalar) and the OpenAPI document it reads,
// generated from the running code. For accounts that may hold API tokens;
// config/scramble.php puts the document route behind the same check.
Route::get('docs/api', ScalarController::class)->middleware(['auth', 'can:tokens.manage_own'])->name('api-reference');
Scramble::registerJsonSpecificationRoute('docs/api/openapi.json')->name('api-reference.document');

require __DIR__.'/settings.php';
