<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests\Support\Worker;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * One request of a user for OneWorker, together with what only this user may
 * see: strings from their own data (a name, an address, a share) that must
 * appear in their response and in nobody else's.
 */
final readonly class UserRequest
{
    /** Request attribute that carries the user to the sign-in listener of OneWorker. */
    public const ATTRIBUTE = 'tesserarr.testing.worker-user';

    /**
     * @param  list<string>  $secrets
     */
    private function __construct(
        public Request $request,
        public ?Authenticatable $user,
        public array $secrets,
    ) {}

    public static function as(Authenticatable $user, Request $request, string ...$secrets): self
    {
        $request->attributes->set(self::ATTRIBUTE, $user);

        return new self($request, $user, array_values($secrets));
    }

    /**
     * The same request without a signed-in user.
     */
    public function anonymous(): self
    {
        $request = $this->request->duplicate();
        $request->attributes->remove(self::ATTRIBUTE);

        return new self($request, null, []);
    }

    /**
     * How failure messages name the user: the model's name, else the identifier.
     */
    public function label(): string
    {
        if ($this->user === null) {
            return 'a guest';
        }
        $name = $this->user instanceof Model ? $this->user->getAttribute('display_name') : null;

        return is_string($name) ? $name : (string) $this->user->getAuthIdentifier();
    }
}
