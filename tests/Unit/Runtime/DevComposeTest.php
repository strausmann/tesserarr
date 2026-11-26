<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

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
        ->and($migrate['command'])->toBe(['artisan', 'tesserarr:migrate'])
        ->and($migrate['environment']['TESSERARR_ROLE'])->toBe('central')
        ->and($migrate['restart'])->toBe('no')
        ->and($migrate['depends_on']['postgres']['condition'])->toBe('service_healthy');
});

it('starts web, worker and scheduler only after the migrations succeeded', function (string $service) {
    expect(devCompose()['services'][$service]['depends_on']['migrate']['condition'] ?? null)
        ->toBe('service_completed_successfully');
})->with(['web', 'worker', 'scheduler']);

it('keeps the backups of tesserarr:migrate in a bind mount', function () {
    $migrate = devCompose()['services']['migrate'];

    expect($migrate['volumes'])->toContain('./.dev/backups:/var/lib/tesserarr/backups')
        ->and(devCompose())->not->toHaveKey('volumes');
});

it('declares a pull policy for every service', function () {
    foreach (devCompose()['services'] as $name => $service) {
        expect($service)->toHaveKey('pull_policy', message: "service {$name} has no pull_policy");
    }
});

it('runs PostgreSQL 18, pinned by digest, with its data under /var/lib/postgresql', function () {
    $postgres = devCompose()['services']['postgres'];

    // From 18 on the image keeps PGDATA in /var/lib/postgresql/18/docker; a mount
    // at /var/lib/postgresql/data would not hold the data.
    expect($postgres['image'])->toMatch('/^postgres:18\.\d+-trixie@sha256:[0-9a-f]{64}$/')
        ->and($postgres['pull_policy'])->toBe('missing')
        ->and($postgres['volumes'])->toContain('./.dev/postgresql:/var/lib/postgresql');
});

it('passes every base image pin of docker/base-images.env to the build, and only those', function () {
    $pins = [];
    foreach (file(dirname(__DIR__, 3).'/docker/base-images.env', FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*_IMAGE)=/', $line, $match) === 1) {
            $pins[$match[1]] = '${'.$match[1].'}';
        }
    }

    // The values come from `--env-file docker/base-images.env`; the Dockerfile
    // has no default for them (scripts/base_images.py checks that side).
    expect($pins)->not->toBeEmpty()
        ->and(devCompose()['services']['web']['build']['args'] ?? null)->toBe($pins);
});
