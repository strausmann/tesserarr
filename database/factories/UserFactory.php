<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Authorization\Roles;
use App\Models\User;
use App\Privacy\BlindIndex;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    /**
     * The current password being used by the factory.
     */
    protected static ?string $password;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'username' => fake()->unique()->regexify('[a-z]{4,10}[0-9]{2}'),
            'display_name' => fake()->name(),
            // No account address: it is optional, and a real one exists only
            // after its confirmation (withConfirmedEmail()).
            'password' => static::$password ??= Hash::make('password'),
            'remember_token' => Str::random(10),
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }

    /**
     * With the built-in role "operator" (every permission assignable to users).
     */
    public function operator(): static
    {
        return $this->withRole(Roles::OPERATOR);
    }

    /**
     * With the given role (it must exist: the built-in ones are migrated).
     */
    public function withRole(string $role): static
    {
        return $this->afterCreating(fn (User $user) => $user->assignRole($role));
    }

    /**
     * With a confirmed account address, as App\Privacy\EmailConfirmation
     * leaves it after the link was opened: the address, its blind index, the
     * time of the confirmation and the claim that makes it unique.
     */
    public function withConfirmedEmail(?string $address = null): static
    {
        return $this->state(function () use ($address) {
            $address ??= fake()->unique()->userName().'@example.org';

            return [
                'email' => $address,
                'email_bidx' => BlindIndex::of($address),
                'email_confirmed_at' => now(),
            ];
        })->afterCreating(fn (User $user) => DB::table('account_email_claims')->insert([
            'bidx' => $user->email_bidx,
            'user_id' => $user->id,
            'kind' => 'confirmed',
        ]));
    }

    /**
     * Indicate that the model has two-factor authentication configured.
     */
    public function withTwoFactor(): static
    {
        return $this->state(fn (array $attributes) => [
            'two_factor_secret' => encrypt('secret'),
            'two_factor_recovery_codes' => encrypt(json_encode(['recovery-code-1'])),
            'two_factor_confirmed_at' => now(),
        ]);
    }
}
