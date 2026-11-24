<?php

use Symfony\Component\Yaml\Yaml;

// compose.dev.yaml is read as data: the dev stack itself is checked by
// starting it (see the report); this test pins the ordering that makes the
// schema exist before any app process touches the database.
function devCompose(): array
{
    return Yaml::parseFile(dirname(__DIR__, 3).'/compose.dev.yaml');
}

it('runs the migrations once in the central role before the app services', function () {
    $migrate = devCompose()['services']['migrate'] ?? null;

    expect($migrate)->not->toBeNull()
        ->and($migrate['command'])->toBe(['artisan', 'migrate', '--force'])
        ->and($migrate['environment']['TESSERARR_ROLE'])->toBe('central')
        ->and($migrate['restart'])->toBe('no')
        ->and($migrate['depends_on']['postgres']['condition'])->toBe('service_healthy');
});

it('starts web, worker and scheduler only after the migrations succeeded', function (string $service) {
    expect(devCompose()['services'][$service]['depends_on']['migrate']['condition'] ?? null)
        ->toBe('service_completed_successfully');
})->with(['web', 'worker', 'scheduler']);
