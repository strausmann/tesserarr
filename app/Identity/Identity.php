<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A sign-in identity of an account at an external method (OIDC, Plex, the
 * header of a forward-auth proxy): the method, which provider of that method
 * (`provider_key`, for example the slug of an OIDC provider) and the subject
 * the provider names the person by. One identity belongs to one account
 * (unique per method, provider and subject).
 *
 * `email` is the address the provider reported. It is not the contact
 * address of the account (`users.email`) and never identifies the account;
 * task 8a encrypts it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string $provider_key
 * @property string $subject
 * @property string|null $email
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
final class Identity extends Model
{
    protected $table = 'identities';

    protected $fillable = ['user_id', 'method', 'provider_key', 'subject', 'email'];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
