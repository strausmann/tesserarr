<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

use App\Models\User;
use App\Privacy\BlindIndex;
use App\Privacy\EncryptedPii;
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
 * `email` is the address the provider reported. It is not the account
 * address (`users.email`), not unique (two accounts may have identities with
 * the same provider address) and never identifies the account. It is
 * encrypted at rest (EncryptedPii); `email_bidx`, its blind index, follows
 * every change of it.
 *
 * @property int $id
 * @property int $user_id
 * @property string $method
 * @property string $provider_key
 * @property string $subject
 * @property string|null $email the provider address (decrypted)
 * @property string|null $email_bidx
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User $user
 */
final class Identity extends Model
{
    protected $table = 'identities';

    protected $fillable = ['user_id', 'method', 'provider_key', 'subject', 'email'];

    protected $hidden = ['email', 'email_bidx'];

    protected static function booted(): void
    {
        self::saving(function (self $identity): void {
            if ($identity->isDirty('email')) {
                $identity->email_bidx = $identity->email === null ? null : BlindIndex::of($identity->email);
            }
        });
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['email' => EncryptedPii::class];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
