<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditEntry;
use App\Audit\AuditOutcome;
use App\Authorization\Roles;
use App\Invitations\InvitationService;
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
 * One call per audited route, as the route's owner would make it. `setup`
 * prepares what the call needs (and may write audit entries of its own: a
 * setting, a requested address); the entries are counted only after it.
 * `call` makes the request; `expect` names the entry the action itself must
 * write: its action and its subject (type and id, or null for "any id").
 * A new audited route without an entry here fails "writes an audit entry for
 * every mutating route".
 *
 * @return array<string, array{setup: Closure(): array<string, mixed>, call: Closure(array<string, mixed>): TestResponse, expect: Closure(array<string, mixed>): array{0: AuditAction, 1: string|null, 2: int|null}}>
 */
function auditedRouteCalls(): array
{
    $app = fn (User $as) => test()->actingAs($as, 'web')->withHeader('X-Requested-With', 'tesserarr')->withSession(['auth.password_confirmed_at' => time()]);
    $operator = fn () => User::factory()->operator()->create();
    $friend = fn () => User::factory()->withRole(Roles::USER)->create();
    $addresses = fn () => app(Settings::class)->set(AccountEmailPolicy::SETTING, true, Actor::cli('test'));
    $onUser = fn (AuditAction $action) => fn (array $c) => [$action, 'user', (int) $c['user']->id];
    $onToken = fn (AuditAction $action) => fn (array $c) => [$action, 'token', (int) $c['token']->id];
    $bearer = fn (User $owner, array $abilities) => test()->withToken($owner->createToken('t', $abilities)->plainTextToken);
    $invitation = fn () => app(InvitationService::class)->issue(Actor::cli('test'))->invitation;
    $onInvitation = fn (AuditAction $action) => fn (array $c) => [$action, 'invitation', isset($c['invitation']) ? (int) $c['invitation']->id : null];
    $onRole = fn (AuditAction $action) => fn (array $c) => [$action, 'role', isset($c['role']) ? (int) $c['role']->id : null];

    return [
        'account.email' => [
            'setup' => function () use ($addresses, $friend) {
                $addresses();

                return ['user' => $friend()];
            },
            'call' => fn (array $c) => test()->actingAs($c['user'], 'web')->put('/account/email', ['email' => 'first@example.org']),
            'expect' => $onUser(AuditAction::EmailRequested),
        ],
        'account.email.resend' => [
            'setup' => function () use ($addresses, $friend) {
                $addresses();
                $user = $friend();
                app(EmailConfirmation::class)->request($user, 'waiting@example.org', Actor::user($user));

                return ['user' => $user];
            },
            'call' => fn (array $c) => test()->actingAs($c['user'], 'web')->post('/account/email/resend'),
            'expect' => $onUser(AuditAction::EmailRequested),
        ],
        'email.confirm' => [
            'setup' => function () use ($addresses, $friend) {
                $addresses();
                $user = $friend();
                app(EmailConfirmation::class)->request($user, 'confirm@example.org', Actor::user($user));
                $token = '';
                Mail::assertQueued(ConfirmEmailMail::class, function (ConfirmEmailMail $mail) use (&$token) {
                    $token = substr($mail->url, (int) strrpos($mail->url, '#') + 1);

                    return true;
                });

                return ['user' => $user, 'link' => $token];
            },
            'call' => fn (array $c) => test()->post('/email/confirm', ['token' => $c['link']]),
            'expect' => $onUser(AuditAction::EmailConfirmed),
        ],
        'profile.update' => [
            'setup' => fn () => ['user' => $friend()],
            'call' => fn (array $c) => test()->actingAs($c['user'], 'web')->patch('/settings/profile', ['username' => 'renamed', 'display_name' => 'Renamed']),
            'expect' => $onUser(AuditAction::UserRenamed),
        ],
        'user-password.update' => [
            'setup' => fn () => ['user' => $friend()],
            'call' => fn (array $c) => test()->actingAs($c['user'], 'web')->put('/settings/password', [
                'current_password' => 'password',
                'password' => 'a-new-passphrase-for-tests',
                'password_confirmation' => 'a-new-passphrase-for-tests',
            ]),
            'expect' => $onUser(AuditAction::PasswordChanged),
        ],
        'users.email' => [
            'setup' => fn () => ['user' => $friend(), 'operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->put("/users/{$c['user']->id}/email", ['email' => 'set@example.org']),
            'expect' => $onUser(AuditAction::EmailRequested),
        ],
        'users.email.release' => [
            'setup' => function () use ($addresses, $friend, $operator) {
                $addresses();
                $user = $friend();
                app(EmailConfirmation::class)->request($user, 'pending@example.org', Actor::user($user));

                return ['user' => $user, 'operator' => $operator()];
            },
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->delete("/users/{$c['user']->id}/email/pending"),
            'expect' => $onUser(AuditAction::EmailReservationReleased),
        ],
        'users.role' => [
            'setup' => fn () => ['user' => $friend(), 'operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->put("/users/{$c['user']->id}/role", ['role' => Roles::OPERATOR]),
            'expect' => $onUser(AuditAction::RoleAssigned),
        ],
        'users.lock' => [
            'setup' => fn () => ['user' => $friend(), 'operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->post("/users/{$c['user']->id}/lock"),
            'expect' => $onUser(AuditAction::UserLocked),
        ],
        'users.unlock' => [
            'setup' => fn () => ['user' => User::factory()->create(['locked_at' => now()]), 'operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->delete("/users/{$c['user']->id}/lock"),
            'expect' => $onUser(AuditAction::UserUnlocked),
        ],
        'roles.store' => [
            'setup' => fn () => ['operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->post('/roles', ['name' => 'helpers', 'permissions' => ['catalog.browse']]),
            'expect' => $onRole(AuditAction::RoleCreated),
        ],
        'roles.update' => [
            'setup' => fn () => ['role' => Role::create(['name' => 'editors', 'guard_name' => Roles::GUARD]), 'operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->put("/roles/{$c['role']->id}", ['permissions' => ['audit.view']]),
            'expect' => $onRole(AuditAction::RoleChanged),
        ],
        'roles.destroy' => [
            'setup' => fn () => ['role' => Role::create(['name' => 'leavers', 'guard_name' => Roles::GUARD]), 'operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->delete("/roles/{$c['role']->id}"),
            'expect' => $onRole(AuditAction::RoleDeleted),
        ],
        'settings.general.update' => [
            'setup' => fn () => ['operator' => $operator()],
            'call' => fn (array $c) => test()->actingAs($c['operator'], 'web')->put('/settings/general', generalForm()),
            'expect' => fn () => [AuditAction::SettingChanged, null, null],
        ],
        'app-api.tokens.store' => [
            'setup' => fn () => ['user' => $friend()],
            'call' => fn (array $c) => $app($c['user'])->postJson('/api/v1/app/tokens', ['name' => 'script', 'permissions' => ['catalog.browse'], 'expires' => '30']),
            'expect' => fn () => [AuditAction::TokenIssued, 'token', null],
        ],
        'app-api.tokens.destroy' => [
            'setup' => function () use ($friend) {
                $user = $friend();

                return ['user' => $user, 'token' => $user->createToken('old', ['catalog.browse'])->accessToken];
            },
            'call' => fn (array $c) => $app($c['user'])->deleteJson("/api/v1/app/tokens/{$c['token']->id}"),
            'expect' => $onToken(AuditAction::TokenRevoked),
        ],
        'app-api.invitations.store' => [
            'setup' => fn () => ['operator' => $operator()],
            'call' => fn (array $c) => $app($c['operator'])->postJson('/api/v1/app/invitations', ['days' => 7, 'note' => 'For Sam']),
            'expect' => $onInvitation(AuditAction::InvitationIssued),
        ],
        'app-api.invitations.destroy' => [
            'setup' => fn () => ['operator' => $operator(), 'invitation' => $invitation()],
            'call' => fn (array $c) => $app($c['operator'])->deleteJson("/api/v1/app/invitations/{$c['invitation']->id}"),
            'expect' => $onInvitation(AuditAction::InvitationRevoked),
        ],
        'api.invitations.store' => [
            'setup' => fn () => ['operator' => $operator()],
            'call' => fn (array $c) => $bearer($c['operator'], ['invitations.create'])->postJson('/api/v1/invitations', ['days' => 3]),
            'expect' => $onInvitation(AuditAction::InvitationIssued),
        ],
        'api.invitations.destroy' => [
            'setup' => fn () => ['operator' => $operator(), 'invitation' => $invitation()],
            'call' => fn (array $c) => $bearer($c['operator'], ['invitations.revoke'])->deleteJson("/api/v1/invitations/{$c['invitation']->id}"),
            'expect' => $onInvitation(AuditAction::InvitationRevoked),
        ],
        'app-api.admin.tokens.destroy' => [
            'setup' => fn () => ['token' => $friend()->createToken('old', ['catalog.browse'])->accessToken, 'operator' => $operator()],
            'call' => fn (array $c) => $app($c['operator'])->deleteJson("/api/v1/app/admin/tokens/{$c['token']->id}"),
            'expect' => $onToken(AuditAction::TokenRevoked),
        ],
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
        $context = $calls[$name]['setup']();
        // Counted after the setup: what it logged is no proof of the action.
        $before = (int) (AuditEntry::query()->max('id') ?? 0);

        $response = $calls[$name]['call']($context);
        [$action, $subjectType, $subjectId] = $calls[$name]['expect']($context);

        $entry = AuditEntry::query()
            ->where('id', '>', $before)
            ->where('action', $action->value)
            ->where('outcome', AuditOutcome::Done)
            ->when($subjectType !== null, fn ($q) => $q->where('subject_type', $subjectType))
            ->when($subjectId !== null, fn ($q) => $q->where('subject_id', $subjectId));

        expect($response->status())->toBeLessThan(400, $name.' answered '.$response->status())
            ->and($entry->exists())->toBeTrue(sprintf('%s wrote no %s entry on %s %s', $name, $action->value, $subjectType ?? 'any subject', $subjectId ?? ''));

        auth()->forgetGuards();
        $this->flushSession();
        $this->flushHeaders();
    }
});

// Carry-over to task 12: the second-factor routes of the starter kit write no
// entry yet (UNAUDITED_MUTATIONS in tests/Support/Routes.php); task 12
// rebuilds them, marks them audited and removes them from that list.
it('logs every change of a second factor')->todo();
