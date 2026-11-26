<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// The exported OpenAPI document (website/openapi/openapi.json) describes the
// token API under /api/v1 and nothing else: the app API under /api/v1/app is
// internal to the web interface (session, ADR-0007) and must never be
// published. Probe routes are registered here, as RoleBoot registers the real
// route files.

use Dedoc\Scramble\Generator;
use Dedoc\Scramble\Scramble;
use Illuminate\Support\Facades\Route;
use Tests\Support\OpenApi\ProbeController;

/**
 * @return array<string, mixed>
 */
function exportedDocument(): array
{
    /** @var array<string, mixed> $document */
    $document = app(Generator::class)(Scramble::getGeneratorConfig(Scramble::DEFAULT_API));

    return $document;
}

/**
 * @return list<string>
 */
function exportedPaths(): array
{
    /** @var array<string, mixed> $paths */
    $paths = exportedDocument()['paths'] ?? [];

    return array_keys($paths);
}

it('exports a /api/v1 route', function () {
    Route::middleware('token-api')->prefix('api/v1')->get('_probe', [ProbeController::class, 'show']);

    expect(exportedPaths())->toBe(['/_probe'])
        ->and(exportedDocument()['servers'][0]['url'] ?? null)->toEndWith('/api/v1');
});

it('exports no /api/v1/app route', function () {
    Route::middleware('token-api')->prefix('api/v1')->get('_probe', [ProbeController::class, 'show']);
    Route::middleware('web')->prefix('api/v1/app')->get('_probe', [ProbeController::class, 'show']);

    expect(exportedPaths())->toBe(['/_probe']);
});

it('exports no route outside /api/v1', function (string $prefix) {
    Route::middleware('token-api')->prefix($prefix)->get('_probe', [ProbeController::class, 'show']);

    expect(exportedPaths())->toBe([]);
})->with(['api', 'api/v2', 'api/v10', 'docs']);

it('documents the permission of the can middleware as the token ability', function () {
    Route::middleware(['token-api', 'can:catalog.manage'])->prefix('api/v1')->put('_probe', [ProbeController::class, 'update']);

    $document = exportedDocument();

    expect($document['paths']['/_probe']['put']['security'] ?? null)->toBe([['apiToken' => ['catalog.manage']]])
        ->and($document['components']['securitySchemes']['apiToken'] ?? null)->toMatchArray(['type' => 'http', 'scheme' => 'bearer']);
});

it('documents only the ability of a can middleware with a model parameter, once', function () {
    Route::middleware(['token-api', 'can:catalog.manage,share', 'can:catalog.manage'])->prefix('api/v1')->put('_probe', [ProbeController::class, 'update']);

    expect(exportedDocument()['paths']['/_probe']['put']['security'] ?? null)->toBe([['apiToken' => ['catalog.manage']]]);
});

it('documents no token ability for a route without a can middleware', function () {
    Route::middleware('token-api')->prefix('api/v1')->get('_probe', [ProbeController::class, 'show']);

    expect(exportedDocument()['paths']['/_probe']['get'])->not->toHaveKey('security');
});

it('exports the export path of the api spec gate', function () {
    expect(config('scramble.export_path'))->toBe('website/openapi/openapi.json');
});
