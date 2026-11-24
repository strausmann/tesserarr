<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Application;
use Tests\Support\EnvSnapshot;

// The role may also come from .env (local artisan, a mounted .env). Routes,
// channels and RoleBoot must then all see the same role.
beforeEach(function () {
    $this->env = (new EnvSnapshot)->set(['TESSERARR_ROLE' => null]);
    $this->envDir = sys_get_temp_dir().'/tesserarr-dotenv-'.bin2hex(random_bytes(4));
    mkdir($this->envDir);
});

afterEach(function () {
    $this->env->restore();
    @unlink($this->envDir.'/.env');
    @rmdir($this->envDir);
    $this->refreshApplication();
});

function bootWithDotenv(string $dir, string $contents): Application
{
    file_put_contents($dir.'/.env', $contents);

    $app = require base_path('bootstrap/app.php');
    $app->useEnvironmentPath($dir);
    $app->make(Kernel::class)->bootstrap();

    return $app;
}

it('boots a consistent satellite when the role is only in .env', function () {
    $app = bootWithDotenv($this->envDir, "TESSERARR_ROLE=satellite\n");

    $uris = collect($app['router']->getRoutes()->getRoutes())->map(fn ($route) => $route->uri())->values()->all();

    expect($app['config']->get('cache.default'))->toBe('array')
        ->and($uris)->toBe(['up']);
});

it('refuses to boot with an unknown role from .env', function () {
    bootWithDotenv($this->envDir, "TESSERARR_ROLE=hub\n");
})->throws(InvalidArgumentException::class, 'TESSERARR_ROLE');
