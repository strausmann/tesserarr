<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use App\Concurrency\ConcurrencyBarrier;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;

/**
 * The two statements that decide whether an invitation can still be used
 * (Spec 9.1, "atomic redemption"). Each is one conditional UPDATE: of two
 * requests for the same invitation PostgreSQL lets exactly one match the
 * row, the other finds the condition false after the first committed and
 * changes nothing. No SELECT before it decides anything.
 *
 * These are the only writes to redeemed_at and the reservation columns
 * (reserved_until, reservation_hash, reserved_user_id, reservation_count).
 */
final class InvitationStore
{
    /** How often an invitation can be reserved (task 11): a cut-off redemption may start again a few times. */
    public const MAX_RESERVATIONS = 5;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ConcurrencyBarrier $barrier,
    ) {}

    /**
     * Uses the invitation up. With $reservationHash null (redeem) a running
     * reservation blocks it, an expired one does not; with a reservation
     * hash (complete, task 11) only the own reservation counts, and only
     * while it runs (H18): an expired own reservation is no way in, even
     * when nobody else reserved since.
     *
     * @return int|null the invitation's id, or null when nothing matched
     */
    public function consume(string $tokenHash, ?string $reservationHash): ?int
    {
        $this->barrier->reached('invitation.consume');

        $row = $this->db->selectOne(<<<'SQL'
            update invitations set redeemed_at = now(), updated_at = now()
            where token_hash = ? and redeemed_at is null and revoked_at is null and expires_at > now()
              and ((cast(? as text) is null and (reserved_until is null or reserved_until < now()))
                   or (reservation_hash = ? and reserved_until > now()))
            returning id
            SQL, [$tokenHash, $reservationHash, $reservationHash]);

        return $row === null ? null : (int) $row->id;
    }

    /**
     * Holds the invitation for one redemption until $until (task 11: the
     * account is made in several steps). At most MAX_RESERVATIONS times.
     *
     * @return int|null the invitation's id, or null when nothing matched
     */
    public function reserve(string $tokenHash, string $reservationHash, ?int $reservedUserId, CarbonImmutable $until): ?int
    {
        $this->barrier->reached('invitation.reserve');

        $row = $this->db->selectOne(<<<'SQL'
            update invitations set reserved_until = ?, reservation_hash = ?, reserved_user_id = ?,
                   reservation_count = reservation_count + 1, updated_at = now()
            where token_hash = ? and redeemed_at is null and revoked_at is null and expires_at > now()
              and (reserved_until is null or reserved_until < now()) and reservation_count < ?
            returning id
            SQL, [$until, $reservationHash, $reservedUserId, $tokenHash, self::MAX_RESERVATIONS]);

        return $row === null ? null : (int) $row->id;
    }
}
