<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditEntry;
use App\Audit\AuditOutcome;
use App\Authorization\Roles;
use App\Mail\ConfirmEmailMail;
use App\Models\User;
use App\Privacy\AccountEmailPolicy;
use App\Privacy\EmailConfirmation;
use App\Settings\Settings;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;

use function Tests\Support\authGroupsOf;
use function Tests\Support\generalForm;
use function Tests\Support\isMutating;
use function Tests\Support\unauditedReason;

/*
| The route inventory (ADR-0007, Spec 12.10): every route belongs to exactly
| one of the groups web, session-api and token-api, and the prefix decides
| which: /api/v1/app/* is the session path, every other /api/v1/* the token
| path. Every route that changes something is marked audited and writes an
| entry; tests/Support/Routes.php names the few that do not, and why.
*/

/**
 * One call per audited route, as the route's owner would make it, with what
 * it needs set up first. A new audited route without a call here fails
 * "writes an audit entry for every mutating route".
 *
 * @return array<string, Closure(): TestResponse>
 */
function auditedRouteCalls(): array
{
    $app = fn (User $as) => test()->actingAs($as, 'web')->withHeader('X-Requested-With', 'tesserarr')->withSession(['auth.password_confirmed_at' => time()]);
    $operator = fn () => User::factory()->operator()->create();
    $friend = fn () => User::factory()->withRole(Roles::USER)->create();

    return [
        'account.email' => function () use ($friend) {
            app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));

            return test()->actingAs($friend(), 'web')->put('/account/email', ['email' => 'first@example.org']);
        },
        'account.email.resend' => function () use ($friend) {
            app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
            $user = $friend();
            app(EmailConfirmation::class)->request($user, 'waiting@example.org', Actor::user($user));

            return test()->actingAs($user, 'web')->post('/account/email/resend');
        },
        'email.confirm' => function () use ($friend) {
            app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
            $user = $friend();
            app(EmailConfirmation::class)->request($user, 'confirm@example.org', Actor::user($user));
            $token = '';
            Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use (&$token) {
                $token = substr($mail->url, (int) strrpos($mail->url, '#') + 1);

                return true;
            });

            return test()->post('/email/confirm', ['token' => $token]);
        },
        'profile.update' => fn () => test()->actingAs($friend(), 'web')->patch('/settings/profile', ['username' => 'renamed', 'display_name' => 'Renamed']),
        'user-password.update' => fn () => test()->actingAs($friend(), 'web')->put('/settings/password', [
            'current_password' => 'password',
            'password' => 'a-new-passphrase-for-tests',
            'password_confirmation' => 'a-new-passphrase-for-tests',
        ]),
        'users.email' => fn () => test()->actingAs($operator(), 'web')->put('/users/'.$friend()->id.'/email', ['email' => 'set@example.org']),
        'users.email.release' => function () use ($operator, $friend) {
            app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
            $user = $friend();
            app(EmailConfirmation::class)->request($user, 'pending@example.org', Actor::user($user));

            return test()->actingAs($operator(), 'web')->delete("/users/{$user->id}/email/pending");
        },
        'users.role' => fn () => test()->actingAs($operator(), 'web')->put('/users/'.$friend()->id.'/role', ['role' => Roles::OPERATOR]),
        'users.lock' => fn () => test()->actingAs($operator(), 'web')->post('/users/'.$friend()->id.'/lock'),
        'users.unlock' => fn () => test()->actingAs($operator(), 'web')->delete('/users/'.User::factory()->create(['locked_at' => now()])->id.'/lock'),
        'roles.store' => fn () => test()->actingAs($operator(), 'web')->post('/roles', ['name' => 'helpers', 'permissions' => ['catalog.browse']]),
        'roles.update' => function () use ($operator) {
            $role = Role::create(['name' => 'editors', 'guard_name' => Roles::GUARD]);

            return test()->actingAs($operator(), 'web')->put('/roles/'.$role->id, ['permissions' => ['audit.view']]);
        },
        'roles.destroy' => function () use ($operator) {
            $role = Role::create(['name' => 'leavers', 'guard_name' => Roles::GUARD]);

            return test()->actingAs($operator(), 'web')->delete('/roles/'.$role->id);
        },
        'settings.general.update' => fn () => test()->actingAs($operator(), 'web')->put('/settings/general', generalForm()),
        'app-api.tokens.store' => fn () => $app($friend())->postJson('/api/v1/app/tokens', ['name' => 'script', 'permissions' => ['catalog.browse'], 'expires' => '30']),
        'app-api.tokens.destroy' => function () use ($app, $friend) {
            $user = $friend();
            $token = $user->createToken('old', ['catalog.browse'])->accessToken;

            return $app($user)->deleteJson("/api/v1/app/tokens/{$token->id}");
        },
        'app-api.admin.tokens.destroy' => function () use ($app, $operator, $friend) {
            $token = $friend()->createToken('old', ['catalog.browse'])->accessToken;

            return $app($operator())->deleteJson("/api/v1/app/admin/tokens/{$token->id}");
        },
    ];
}

/** @return list<Route> */
function allRoutes(): array
{
    return array_values(RouteFacade::getRoutes()->getRoutes());
}

it('assigns every route to exactly one auth group', function () {
    $wrong = [];

    foreach (allRoutes() as $route) {
        $groups = authGroupsOf($route);
        $uri = $route->uri();

        $expected = match (true) {
            in_array($uri, Tests\Support\UNGROUPED_ROUTES, true) => [],
            str_starts_with($uri, 'api/v1/app/') || $uri === 'api/v1/app' => ['session-api'],
            str_starts_with($uri, 'api/v1/') || $uri === 'api/v1' => ['token-api'],
            default => ['web'],
        };

        if ($groups !== $expected) {
            $wrong[] = sprintf('%s %s: [%s], expected [%s]', implode('|', $route->methods()), $uri, implode(', ', $groups), implode(', ', $expected));
        }
    }

    expect($wrong)->toBe([]);
});

it('fails the inventory for a route without a group, naming its uri', function () {
    RouteFacade::get('/_no-group', fn () => 'open');
    RouteFacade::middleware('web')->prefix('api/v1')->get('_web-on-token-path', fn () => 'open');
    RouteFacade::getRoutes()->refreshNameLookups();

    $byUri = collect(allRoutes())->keyBy(fn (Route $r) => $r->uri());

    expect(authGroupsOf($byUri['_no-group']))->toBe([])
        ->and(authGroupsOf($byUri['api/v1/_web-on-token-path']))->toBe(['web']);
});

it('marks every mutating route as audited or names why it is not', function () {
    $unmarked = [];

    foreach (allRoutes() as $route) {
        if (! isMutating($route)) {
            continue;
        }

        $audited = ($route->defaults['audited'] ?? false) === true;
        $reason = unauditedReason($route);

        if ($audited === ($reason !== null)) {
            $unmarked[] = sprintf('%s %s: %s', implode('|', $route->methods()), $route->uri(), $audited ? 'audited and exempt' : 'neither audited nor exempt');
        }
    }

    expect($unmarked)->toBe([]);
});

it('writes an audit entry for every mutating route', function () {
    Mail::fake();
    $calls = auditedRouteCalls();
    $audited = array_values(array_filter(allRoutes(), fn (Route $r) => ($r->defaults['audited'] ?? false) === true));

    expect(array_map(fn (Route $r) => (string) $r->getName(), $audited))->toEqualCanonicalizing(array_keys($calls));

    foreach ($audited as $route) {
        $name = (string) $route->getName();
        $before = AuditEntry::query()->where('outcome', AuditOutcome::Done)->max('id') ?? 0;

        $response = $calls[$name]();

        expect($response->status())->toBeLessThan(400, $name.' answered '.$response->status())
            ->and(AuditEntry::query()->where('outcome', AuditOutcome::Done)->where('id', '>', $before)->count())
            ->toBeGreaterThanOrEqual(1, $name.' wrote no audit entry');

        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
    }
});

// Carry-over to task 12: the second-factor routes of the starter kit write no
// entry yet (UNAUDITED_MUTATIONS in tests/Support/Routes.php); task 12
// rebuilds them, marks them audited and removes them from that list.
it('logs every change of a second factor')->todo();
