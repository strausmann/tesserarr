<?php

use App\Http\Controllers\Testing\E2eLoginController;
use Illuminate\Support\Facades\Route;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// Browser tests only; 404 outside the e2e image (E2eLoginController::enabled).
Route::get('_testing/login/{role}', E2eLoginController::class)->name('testing.login');

require __DIR__.'/settings.php';
