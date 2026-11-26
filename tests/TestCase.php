<?php

declare(strict_types=1);

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Laravel\Fortify\Features;

abstract class TestCase extends BaseTestCase
{
    /**
     * The environment of the machine that runs the tests must not override a
     * general setting (TZ is set almost everywhere): the override variables
     * (config/tesserarr.php, setting_env) start empty in every test. A test
     * of the override sets one itself.
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['tesserarr.setting_env' => array_fill_keys(array_keys((array) config('tesserarr.setting_env')), null)]);
    }

    protected function skipUnlessFortifyHas(string $feature, ?string $message = null): void
    {
        if (! Features::enabled($feature)) {
            $this->markTestSkipped($message ?? "Fortify feature [{$feature}] is not enabled.");
        }
    }
}
