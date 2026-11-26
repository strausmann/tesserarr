<?php

declare(strict_types=1);

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Whether a newer release of Tesserarr exists (App\Updates\ReleaseCheck),
// shown to the operator under "About". Off with TESSERARR_RELEASE_CHECK=false.
Schedule::command('tesserarr:release-check')->everySixHours()->withoutOverlapping();
