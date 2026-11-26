<?php

declare(strict_types=1);

use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Scalar\Controllers\ScalarController;

Route::inertia('/', 'Welcome')->name('home');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::inertia('dashboard', 'Dashboard')->name('dashboard');
});

// The interactive API reference (Scalar) and the OpenAPI document it reads,
// generated from the running code. Signed-in users only; config/scramble.php
// puts the document route behind `auth` as well.
Route::get('docs/api', ScalarController::class)->middleware('auth')->name('api-reference');
Scramble::registerJsonSpecificationRoute('docs/api/openapi.json')->name('api-reference.document');

require __DIR__.'/settings.php';
