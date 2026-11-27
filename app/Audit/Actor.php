<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Audit;

use App\Models\User;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Who triggered an action: a user in the web interface, the operator's
 * console (`artisan`, by the OS user), an API token or a satellite. The audit
 * log stores the kind and an id, never an e-mail address.
 */
final readonly class Actor
{
    public const USER = 'user';

    public const CLI = 'cli';

    public const TOKEN = 'token';

    public const SATELLITE = 'satellite';

    /** Somebody not signed in, such as a person redeeming an invitation that cannot be used. */
    public const GUEST = 'guest';

    /**
     * @param  self::USER|self::CLI|self::TOKEN|self::SATELLITE|self::GUEST  $type
     */
    private function __construct(
        public string $type,
        public ?int $id,
        public ?string $label,
        public ?User $user,
    ) {}

    public static function user(User $user): self
    {
        return new self(self::USER, (int) $user->getKey(), null, $user);
    }

    /** The console of the operator; $osUser is the account that ran the command. */
    public static function cli(string $osUser): self
    {
        return new self(self::CLI, null, $osUser, null);
    }

    /** The console, named by the OS user running this process. */
    public static function console(): self
    {
        $name = null;
        if (function_exists('posix_geteuid') && function_exists('posix_getpwuid')) {
            $entry = posix_getpwuid(posix_geteuid());
            $name = is_array($entry) ? $entry['name'] : null;
        }

        return self::cli($name ?? (string) (getenv('USER') ?: 'unknown'));
    }

    /** An API token of a user: the id is the owner's, the label the token's id. */
    public static function token(PersonalAccessToken $token, User $owner): self
    {
        return new self(self::TOKEN, (int) $owner->getKey(), (string) $token->getKey(), $owner);
    }

    /** Not signed in (the log names no account). */
    public static function guest(): self
    {
        return new self(self::GUEST, null, 'guest', null);
    }

    public static function satellite(int $id, string $name): self
    {
        return new self(self::SATELLITE, $id, $name, null);
    }

    /** Where the action came from, as the audit log shows it. */
    public function source(): string
    {
        return match ($this->type) {
            self::USER, self::GUEST => 'ui',
            self::CLI => 'cli',
            self::TOKEN, self::SATELLITE => 'api',
        };
    }
}
