<?php

declare(strict_types=1);

use App\Runtime\InstanceRole;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Whether a newer release of Tesserarr exists (App\Updates\ReleaseCheck),
// shown to the operator under "About"; a no-op unless
// TESSERARR_RELEASE_CHECK=true. Central instance only: a satellite has no
// interface to show it and only an array cache to keep it in. The lock is
// released after 10 minutes, so a crashed run does not block the next day.
if (app(InstanceRole::class) === InstanceRole::Central) {
    Schedule::command('tesserarr:release-check')->everySixHours()->withoutOverlapping(10);
    // An unconfirmed account address holds its blind index for 24 hours at
    // most (App\Privacy\EmailConfirmation).
    Schedule::command('tesserarr:email:release-expired')->hourly()->withoutOverlapping(10);
}
