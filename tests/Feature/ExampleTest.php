<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('sends a guest from the start to the sign-in', function () {
    $this->get(route('home'))->assertRedirect(route('login'));
});

test('sends a friend to their home and an operator to the dashboard', function () {
    $this->actingAs(User::factory()->withRole('user')->create())->get(route('home'))->assertRedirect(route('friend.home'));
    $this->actingAs(User::factory()->operator()->create())->get(route('home'))->assertRedirect(route('dashboard'));
});
