<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// The interactive API reference (Scalar) at /docs/api and the document it
// reads are for signed-in users only. The page loads nothing from a CDN and
// sends nothing to Scalar's servers: the bundle comes from the app's own build.

use App\Models\User;

/**
 * The configuration the page hands to Scalar.createApiReference, decoded.
 *
 * @return array<string, mixed>
 */
function scalarConfigurationOf(string $html): array
{
    expect(preg_match("/Scalar\\.createApiReference\\('#app', (\\{.*\\})\\)/s", $html, $match))->toBe(1);

    /** @var array<string, mixed> $configuration */
    $configuration = json_decode($match[1], true, flags: JSON_THROW_ON_ERROR);

    return $configuration;
}

it('sends a guest from the api reference to the login', function (string $uri) {
    $this->get($uri)->assertRedirect(route('login'));
})->with(['/docs/api', '/docs/api/openapi.json']);

it('shows the api reference to a signed-in user', function () {
    $this->actingAs(User::factory()->create())
        ->get('/docs/api')
        ->assertOk()
        ->assertSee('<script src="/build/scalar/standalone.js"></script>', false);
});

it('points the api reference at the generated document', function () {
    $html = (string) $this->actingAs(User::factory()->create())->get('/docs/api')->assertOk()->getContent();

    expect(scalarConfigurationOf($html)['url'] ?? null)->toBe('/docs/api/openapi.json');
});

it('serves the openapi document to a signed-in user', function () {
    $this->actingAs(User::factory()->create())
        ->getJson('/docs/api/openapi.json')
        ->assertOk()
        ->assertJsonPath('info.title', 'Tesserarr API')
        ->assertJsonPath('servers.0.url', '/api/v1');
});

it('loads nothing from a cdn and uses no scalar proxy, fonts or telemetry', function () {
    $html = (string) $this->actingAs(User::factory()->create())->get('/docs/api')->assertOk()->getContent();
    $configuration = scalarConfigurationOf($html);

    expect($html)->not->toContain('cdn.jsdelivr.net')
        ->and($html)->not->toContain('scalar.com')
        ->and($configuration['proxyUrl'] ?? null)->toBe('')
        ->and($configuration['withDefaultFonts'] ?? null)->toBeFalse()
        ->and($configuration['telemetry'] ?? null)->toBeFalse()
        ->and($configuration['persistAuth'] ?? null)->toBeFalse();
});

it('registers neither the ui of scramble nor the default scalar route', function (string $uri) {
    $this->actingAs(User::factory()->create())->get($uri)->assertNotFound();
})->with(['/docs/api.json', '/scalar', '/_scramble/dev-tools/devtools.js']);
