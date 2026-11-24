<?php

namespace App\Providers;

use App\Concurrency\AdvisoryConcurrencyBarrier;
use App\Concurrency\ConcurrencyBarrier;
use App\Concurrency\NullConcurrencyBarrier;
use App\Database\Backup\DatabaseDumper;
use App\Database\Backup\PgDump;
use App\Runtime\InstanceRole;
use App\Runtime\RoleBoot;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        RoleBoot::apply($this->app, InstanceRole::fromEnv());

        $this->app->singleton(ConcurrencyBarrier::class, fn (Application $app) => self::barrier($app));

        // The parsed configuration of the live connection (DB_URL included).
        $this->app->bind(DatabaseDumper::class, fn () => new PgDump(
            DB::connection()->getConfig(),
            (string) config('tesserarr.backup.pg_dump'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        TrustProxies::at(config('tesserarr.trusted_proxies'));
    }

    /**
     * The advisory barrier exists for race tests only: it is bound when the
     * environment is "testing" AND TESSERARR_TEST_BARRIER=advisory. Anywhere
     * else the variable is ignored.
     */
    private static function barrier(Application $app): ConcurrencyBarrier
    {
        if (! $app->environment('testing') || Env::get('TESSERARR_TEST_BARRIER') !== 'advisory') {
            return new NullConcurrencyBarrier;
        }

        return new AdvisoryConcurrencyBarrier(
            (string) Env::get('TESSERARR_TEST_BARRIER_POINT'),
            (int) Env::get('TESSERARR_TEST_BARRIER_KEY'),
        );
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
