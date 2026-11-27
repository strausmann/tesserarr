<?php

declare(strict_types=1);

namespace App\Providers;

use App\Authorization\Authorizer;
use App\Authorization\Permission;
use App\Authorization\PermissionSubject;
use App\Concurrency\AdvisoryConcurrencyBarrier;
use App\Concurrency\ConcurrencyBarrier;
use App\Concurrency\NullConcurrencyBarrier;
use App\Database\Backup\DatabaseDumper;
use App\Database\Backup\PgDump;
use App\Database\MigrationLock;
use App\Database\PostgresMigrationLock;
use App\Privacy\PiiEncrypter;
use App\Privacy\PiiKeys;
use App\Privacy\RedactingLogManager;
use App\Runtime\InstanceRole;
use App\Runtime\RoleBoot;
use App\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\Response;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Env;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Sanctum\Contracts\HasAbilities;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\Sanctum;

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
        $this->app->bind(MigrationLock::class, fn () => new PostgresMigrationLock(DB::connection()));

        // Stateless: it reads request and guard on every call. A binding, not
        // a class name in the gates, so a test can decorate it.
        $this->app->bind(Authorizer::class);

        // Personal data (task 8a): the keys are fixed for the process and read
        // from the environment only. Built on first use, so artisan commands
        // that never touch an address (key:generate, migrate on an empty
        // database) run without them; the long-running processes check them
        // while they boot (boot()).
        // worker-safe: the keys come from the environment, which is fixed for the process; nothing of a request is read.
        $this->app->singleton(PiiKeys::class, fn (Application $app) => PiiKeys::fromConfig(
            (array) $app->make('config')->get('tesserarr.pii'),
            self::stringOrNull($app->make('config')->get('app.key')),
        ));
        $this->app->singleton(PiiEncrypter::class, fn (Application $app) => new PiiEncrypter($app->make(PiiKeys::class)));

        // No address reaches a log, not even through the emergency logger.
        $this->app->singleton('log', fn (Application $app) => new RedactingLogManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        $this->refuseToServeWithoutPiiKeys();

        TrustProxies::at(config('tesserarr.trusted_proxies'));

        $this->definePermissionGates();

        $this->refuseTokensOfBlockedOwners();

        $this->limitTheTokenApi();

        // The queue worker and the scheduler run for the whole process with one
        // "request" object; the settings read for one job or task are dropped
        // before the next (SettingsRegistry::forget()).
        Event::listen(
            [JobProcessing::class, ScheduledTaskStarting::class],
            static fn () => app(SettingsRegistry::class)->forget(),
        );
    }

    /**
     * The processes of the central instance that read or write personal data
     * do not start without usable keys (PiiKeys): a web request, the queue
     * worker, the scheduler and the commands that migrate or rotate the
     * data. A satellite holds no personal data.
     */
    private function refuseToServeWithoutPiiKeys(): void
    {
        if (app(InstanceRole::class) !== InstanceRole::Central) {
            return;
        }

        $serves = ! $this->app->runningInConsole() || $this->app->runningConsoleCommand(
            'queue:work', 'queue:listen', 'schedule:work', 'schedule:run', 'tesserarr:migrate', 'tesserarr:pii:rotate',
        );

        if ($serves) {
            $this->app->make(PiiKeys::class);
        }
    }

    public const TOKEN_API_TOKEN_LIMITER = 'token-api-token';

    /** Requests per minute and source address on the token path, valid token or not. */
    public const TOKEN_API_PER_ADDRESS = 600;

    /** Requests per minute and token on the token path. */
    public const TOKEN_API_PER_TOKEN = 300;

    private static function stringOrNull(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    /**
     * One gate per permission of the catalogue, and each of them only asks
     * the Authorizer (ADR-0016). The credential is the token Sanctum resolved
     * for the request, or null for a session (sanctum.guard = []). A refusal
     * carries the reason of the decision as its message.
     */
    private function definePermissionGates(): void
    {
        foreach (Permission::cases() as $permission) {
            Gate::define($permission->value, static function (Authenticatable $user) use ($permission): Response {
                $credential = method_exists($user, 'currentAccessToken') ? $user->currentAccessToken() : null;
                $decision = app(Authorizer::class)->allows(
                    $user,
                    $credential instanceof HasAbilities ? $credential : null,
                    $permission,
                );

                return $decision->allowed ? Response::allow() : Response::deny($decision->reason);
            });
        }
    }

    /**
     * The rate limits of the token path (bootstrap/app.php, group token-api),
     * generous on purpose: the arr apps poll an indexer regularly and search
     * in bursts. Per token after the sign-in, here; per source address before
     * it, in App\Http\Middleware\LimitTokenPathByAddress.
     */
    private function limitTheTokenApi(): void
    {
        RateLimiter::for(self::TOKEN_API_TOKEN_LIMITER, static function (Request $request) {
            $token = $request->user()?->currentAccessToken();

            return Limit::perMinute(self::TOKEN_API_PER_TOKEN)
                ->by($token instanceof PersonalAccessToken ? 'token:'.$token->getKey() : 'address:'.$request->ip());
        });
    }

    /**
     * A token of a locked account is no sign-in at all (ADR-0008 point 5):
     * not even on a self-service route that asks no permission. Sanctum
     * already refuses expired tokens; this adds the owner's state, read
     * from the database on every request (User::isBlocked).
     */
    private function refuseTokensOfBlockedOwners(): void
    {
        Sanctum::authenticateAccessTokensUsing(static function (PersonalAccessToken $token, bool $isValid): bool {
            $owner = $token->getAttribute('tokenable');

            return $isValid && $owner instanceof PermissionSubject && ! $owner->isBlocked();
        });
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
