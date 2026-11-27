<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use App\Models\User;
use App\Privacy\EncryptedPii;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation (Spec 9.1). Only the SHA-256 of its token is stored.
 *
 * Read-only for everything but InvitationService and InvitationStore: the
 * columns that decide whether it can still be redeemed (redeemed_at and the
 * reservation) are written by the two statements of InvitationStore only.
 *
 * @property int $id
 * @property string $token_hash
 * @property InvitationKind $kind
 * @property int|null $for_user_id
 * @property string|null $note the operator's label (decrypted)
 * @property array{type: string, id: int|null, label: string|null} $created_by_actor
 * @property Carbon $expires_at
 * @property Carbon|null $redeemed_at
 * @property int|null $redeemed_by_user_id
 * @property string|null $redeemed_ip
 * @property Carbon|null $revoked_at
 * @property Carbon|null $reserved_until
 * @property int $reservation_count
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property-read User|null $forUser
 */
final class Invitation extends Model
{
    protected $table = 'invitations';

    protected $guarded = ['*'];

    protected $hidden = ['token_hash', 'reservation_hash', 'note'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'kind' => InvitationKind::class,
            'note' => EncryptedPii::class,
            'created_by_actor' => 'array',
            'expires_at' => 'datetime',
            'redeemed_at' => 'datetime',
            'revoked_at' => 'datetime',
            'reserved_until' => 'datetime',
            'reservation_count' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function forUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'for_user_id');
    }

    /**
     * Not redeemed, not revoked, not expired.
     *
     * @param  Builder<self>  $query
     */
    public function scopeOpen(Builder $query): void
    {
        $query->whereNull('redeemed_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    public static function hashOf(string $plainToken): string
    {
        return hash('sha256', $plainToken);
    }
}
