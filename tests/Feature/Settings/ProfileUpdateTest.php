<?php

declare(strict_types=1);

use App\Audit\AuditEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

test('profile page is displayed', function () {
    $user = User::factory()->create();

    $response = $this
        ->actingAs($user)
        ->get(route('profile.edit'));

    $response->assertOk();
});

test('username and display name can be updated, and the rename is logged', function () {
    $user = User::factory()->create(['username' => 'before']);

    $this->actingAs($user)
        ->patch(route('profile.update'), ['username' => 'after', 'display_name' => 'Test User'])
        ->assertSessionHasNoErrors()
        ->assertRedirect(route('profile.edit'));

    $user->refresh();
    $entry = AuditEntry::query()->where('action', 'user.rename')->sole();

    expect($user->username)->toBe('after')
        ->and($user->display_name)->toBe('Test User')
        ->and($entry->context)->toEqual(['from' => 'before', 'to' => 'after']);
});

test('the user can sign in with the new username', function () {
    $user = User::factory()->create(['username' => 'before']);

    $this->actingAs($user)->patch(route('profile.update'), ['username' => 'After', 'display_name' => '']);
    $this->post(route('logout'));
    $this->post(route('login.store'), ['login' => 'after', 'password' => 'password']);

    $this->assertAuthenticatedAs($user);
});

test('the username must be unique', function () {
    User::factory()->create(['username' => 'taken']);
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('profile.edit'))
        ->patch(route('profile.update'), ['username' => 'Taken', 'display_name' => ''])
        ->assertSessionHasErrors('username')
        ->assertRedirect(route('profile.edit'));
});

test('the contact address cannot be changed here', function () {
    $user = User::factory()->create(['email' => 'kept@example.org']);

    $this->actingAs($user)->patch(route('profile.update'), [
        'username' => $user->username,
        'display_name' => '',
        'email' => 'other@example.org',
    ])->assertSessionHasNoErrors();

    expect($user->refresh()->email)->toBe('kept@example.org');
});

test('there is no route to delete the own account', function () {
    expect(Route::has('profile.destroy'))->toBeFalse();

    $user = User::factory()->create();
    $this->actingAs($user)->delete('/settings/profile')->assertMethodNotAllowed();

    expect($user->fresh())->not->toBeNull();
});

test('a username taken between the check and the save is a validation error', function () {
    $user = User::factory()->create(['username' => 'before']);
    // Another request takes the name after the validation has passed.
    User::updating(function (User $updating) use ($user) {
        if ($updating->is($user)) {
            DB::table('users')->insert(['username' => 'after', 'display_name' => '']);
        }
    });

    $this->actingAs($user)->from(route('profile.edit'))
        ->patch(route('profile.update'), ['username' => 'after', 'display_name' => ''])
        ->assertRedirect(route('profile.edit'))
        ->assertSessionHasErrors('username');

    expect($user->fresh()?->username)->toBe('before');
});
