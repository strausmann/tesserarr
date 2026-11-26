<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Providers;

use ReflectionClass;
use Scalar\ScalarServiceProvider as PackageProvider;
use Spatie\LaravelPackageTools\Package;

/**
 * Scalar's provider without its route. The package registers /scalar for
 * every instance role; the API reference belongs to the central role only and
 * sits behind the sign-in, so routes/web.php registers it at /docs/api. The
 * package provider is excluded from discovery (composer.json, dont-discover).
 */
final class ScalarServiceProvider extends PackageProvider
{
    public function configurePackage(Package $package): void
    {
        $package
            ->name('scalar')
            ->hasConfigFile()
            ->hasViews('scalar');
    }

    /**
     * The package's own directory, so its views and config are found although
     * this class lives in the application.
     */
    protected function getPackageBaseDir(): string
    {
        return dirname((string) (new ReflectionClass(PackageProvider::class))->getFileName());
    }
}
