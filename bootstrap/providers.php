<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\FortifyServiceProvider;
use App\Providers\OpenApiServiceProvider;
use App\Providers\ScalarServiceProvider;

return [
    AppServiceProvider::class,
    FortifyServiceProvider::class,
    OpenApiServiceProvider::class,
    ScalarServiceProvider::class,
];
