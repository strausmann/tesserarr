<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Concurrency\ConcurrencyBarrier;
use App\Mail\ConfirmEmailMail;
use App\Mail\EmailChangeRequestedMail;
use App\Models\User;
use Illuminate\Cache\RateLimiter;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Mail;

/**
 * The account address and its confirmation (task 8a, Spec 16.16). The one
 * writer of users.email, email_bidx, email_confirmed_at, pending_email*
 * and account_email_claims (PHPStan rule OnlyEmailConfirmationWritesAccountEmail;
 * App\Privacy\PiiRotation rewrites them under a new key only).
 *
 * The address is a sign-in name (task 11) and the target of mail, so it only
 * counts once its owner opened the link sent to it:
 *
 * - request() writes the new address to pending_email and reserves it for
 *   RESERVATION_HOURS in account_email_claims, whose primary key on the blind
 *   index makes an address unique across confirmed and reserved addresses.
 *   A confirmed old address stays valid until then and is told about the
 *   change. The link holds a token of TOKEN_BYTES random bytes; only its
 *   SHA-256 is stored, valid for LINK_MINUTES. Asking again within the
 *   reservation sends a new link and keeps the reservation's end.
 * - confirm() uses the token once (UPDATE … WHERE used_at IS NULL AND
 *   expires_at > now()) and moves the reserved address to email.
 * - release() (an operator) and releaseExpired() (the scheduler, hourly)
 *   free a reservation; request() frees an expired one of the same address
 *   in its own transaction first, so an address is blocked for 24 hours at
 *   most by somebody who cannot read its mailbox.
 *
 * "This address belongs to another account" is a deliberate answer (Spec
 * 16.16): it never names the account, every refusal is logged
 * (email.claim_rejected), and a user can check at most CHECKS_PER_HOUR
 * addresses an hour.
 *
 * A fresh second factor for changing the own address arrives with
 * ConfirmationProof in task 11 (pre-flight K14); until then a user enters a
 * first address only (AccountController), an operator any (UserController).
 */
final class EmailConfirmation
{
    public const RESERVATION_HOURS = 24;

    public const LINK_MINUTES = 60;

    public const CHECKS_PER_HOUR = 10;

    public const TOKEN_BYTES = 32;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly ConcurrencyBarrier $barrier,
        private readonly AuditLogger $audit,
        private readonly AccountEmailPolicy $policy,
        private readonly RateLimiter $limiter,
    ) {}

    /**
     * Reserves $email for $user and sends the link. $authorise runs first in
     * the transaction of the change (an operator's permission check under
     * its locks, RoleGuard); what it throws leaves the method unchanged.
     *
     * @param  (callable(): void)|null  $authorise
     *
     * @throws EmailEntryDisabled when the operator switch is off or no transport exists
     * @throws EmailClaimThrottled after CHECKS_PER_HOUR checks of the actor within the hour
     * @throws EmailAlreadyClaimed when another account holds the address
     */
    public function request(User $user, string $email, Actor $by, ?callable $authorise = null): void
    {
        $refusal = $this->policy->refusal();
        if ($refusal !== null) {
            throw new EmailEntryDisabled($refusal);
        }

        $this->throttle($by);

        $email = trim($email);
        $bidx = BlindIndex::of($email);
        $now = Date::now();

        try {
            $sent = $this->db->transaction(function () use ($user, $email, $bidx, $now, $by, $authorise): ?array {
                if ($authorise !== null) {
                    $authorise();
                }

                $row = $this->db->table('users')->where('id', $user->id)->lockForUpdate()
                    ->first(['email_bidx', 'email_confirmed_at', 'pending_email_bidx', 'pending_email_reserved_until']);
                if ($row === null) {
                    return null;
                }

                if ($row->email_bidx === $bidx && $row->email_confirmed_at !== null) {
                    return null; // already the confirmed address of this account
                }

                $this->dropExpiredReservation($bidx, $now);

                $ownReservation = $row->pending_email_bidx === $bidx
                    && $row->pending_email_reserved_until !== null
                    && Date::parse((string) $row->pending_email_reserved_until)->isAfter($now);

                if (! $ownReservation) {
                    $this->dropReservationOf($user->id);
                }

                $this->barrier->reached('email.claim');

                if (! $ownReservation) {
                    $until = $now->addHours(self::RESERVATION_HOURS);
                    $this->db->table('account_email_claims')->insert([
                        'bidx' => $bidx, 'user_id' => $user->id, 'kind' => 'pending', 'reserved_until' => $until,
                    ]);
                    $this->db->table('users')->where('id', $user->id)->update([
                        'pending_email' => app(PiiEncrypter::class)->encrypt($email),
                        'pending_email_bidx' => $bidx,
                        'pending_email_reserved_until' => $until,
                    ]);
                }

                // A new link replaces every older one of the account.
                $this->db->table('email_confirmations')->where('user_id', $user->id)->whereNull('used_at')->update(['used_at' => $now]);
                $token = rtrim(strtr(base64_encode(random_bytes(self::TOKEN_BYTES)), '+/', '-_'), '=');
                $this->db->table('email_confirmations')->insert([
                    'user_id' => $user->id,
                    'email_bidx' => $bidx,
                    'token_hash' => hash('sha256', $token),
                    'expires_at' => $now->addMinutes(self::LINK_MINUTES),
                    'created_at' => $now,
                ]);

                $this->audit->record(AuditAction::EmailRequested, $by, $user, ['resent' => $ownReservation]);

                return ['token' => $token, 'old' => $row->email_confirmed_at !== null && ! $ownReservation];
            });
        } catch (UniqueConstraintViolationException) {
            $this->audit->record(AuditAction::EmailClaimRejected, $by, $user, [], AuditOutcome::Denied);

            throw new EmailAlreadyClaimed;
        }

        if ($sent === null) {
            return;
        }

        $fresh = $user->fresh() ?? $user;
        Mail::to($email)->locale($fresh->locale)->queue((new ConfirmEmailMail(
            route('email.confirm.show', ['token' => $sent['token']]),
            self::LINK_MINUTES,
            $fresh->username,
        ))->afterCommit());

        $old = $fresh->confirmedEmail();
        if ($sent['old'] && $old !== null) {
            Mail::to($old)->locale($fresh->locale)->queue((new EmailChangeRequestedMail($fresh->username))->afterCommit());
        }
    }

    /**
     * Uses the link once and makes the reserved address the account address.
     *
     * @throws InvalidConfirmationLink
     */
    public function confirm(string $token): User
    {
        $now = Date::now();

        $userId = $this->db->transaction(function () use ($token, $now): int {
            $link = $this->db->selectOne(
                'update email_confirmations set used_at = ? where token_hash = ? and used_at is null and expires_at > ? returning user_id, email_bidx',
                [$now, hash('sha256', $token), $now],
            );
            if ($link === null) {
                throw new InvalidConfirmationLink;
            }

            $user = $this->db->table('users')->where('id', $link->user_id)->lockForUpdate()
                ->first(['pending_email_bidx', 'pending_email_reserved_until']);
            $claim = $this->db->table('account_email_claims')->where('bidx', $link->email_bidx)->lockForUpdate()->first(['user_id', 'kind']);

            $valid = $user !== null
                && $user->pending_email_bidx === $link->email_bidx
                && $user->pending_email_reserved_until !== null
                && Date::parse((string) $user->pending_email_reserved_until)->isAfter($now)
                && $claim !== null && (int) $claim->user_id === (int) $link->user_id && $claim->kind === 'pending';

            if (! $valid) {
                // Keep the link used: a refused link is not tried a second time.
                return 0;
            }

            $this->db->table('account_email_claims')->where('user_id', $link->user_id)->where('kind', 'confirmed')->delete();
            $this->db->table('account_email_claims')->where('bidx', $link->email_bidx)
                ->update(['kind' => 'confirmed', 'reserved_until' => null]);
            // The ciphertext moves as it is; the address is never decrypted here.
            $this->db->update(
                'update users set email = pending_email, email_bidx = pending_email_bidx, email_confirmed_at = ?, '
                .'pending_email = null, pending_email_bidx = null, pending_email_reserved_until = null where id = ?',
                [$now, $link->user_id],
            );

            return (int) $link->user_id;
        });

        if ($userId === 0) {
            throw new InvalidConfirmationLink;
        }

        $user = User::query()->findOrFail($userId);
        $this->audit->record(AuditAction::EmailConfirmed, Actor::user($user), $user);

        return $user;
    }

    /**
     * An operator frees the reservation of $user at once (logged).
     *
     * @param  (callable(): void)|null  $authorise  as in request()
     */
    public function release(User $user, Actor $by, ?callable $authorise = null): void
    {
        $this->db->transaction(function () use ($user, $by, $authorise): void {
            if ($authorise !== null) {
                $authorise();
            }
            $this->db->table('users')->where('id', $user->id)->lockForUpdate()->first(['id']);
            if ($this->dropReservationOf($user->id) > 0) {
                $this->audit->record(AuditAction::EmailReservationReleased, $by, $user);
            }
        });
    }

    /** The scheduler, hourly: frees every reservation that ran out. Returns their number. */
    public function releaseExpired(): int
    {
        $now = Date::now();

        return $this->db->transaction(function () use ($now): int {
            $released = $this->db->table('account_email_claims')
                ->where('kind', 'pending')->where('reserved_until', '<=', $now)->delete();
            $this->db->table('users')->whereNotNull('pending_email_reserved_until')->where('pending_email_reserved_until', '<=', $now)
                ->update(['pending_email' => null, 'pending_email_bidx' => null, 'pending_email_reserved_until' => null]);

            return $released;
        });
    }

    /**
     * The account whose confirmed address $email is, for the sign-in by
     * address (task 11). A reserved, unconfirmed address finds nobody.
     */
    public function confirmedOwnerOf(string $email): ?User
    {
        return User::query()->where('email_bidx', BlindIndex::of($email))->whereNotNull('email_confirmed_at')->first();
    }

    /** @throws EmailClaimThrottled */
    private function throttle(Actor $by): void
    {
        if ($by->type !== Actor::USER) {
            return;
        }

        $key = 'email-claim:'.$by->id;
        if ($this->limiter->tooManyAttempts($key, self::CHECKS_PER_HOUR)) {
            throw new EmailClaimThrottled($this->limiter->availableIn($key));
        }

        $this->limiter->hit($key, 3600);
    }

    /** A reservation of $bidx that ran out no longer holds the address. */
    private function dropExpiredReservation(string $bidx, \DateTimeInterface $now): void
    {
        $expired = $this->db->table('account_email_claims')
            ->where('bidx', $bidx)->where('kind', 'pending')->where('reserved_until', '<=', $now)
            ->lockForUpdate()->value('user_id');

        if ($expired !== null) {
            $this->dropReservationOf((int) $expired);
        }
    }

    /** Frees the reservation of an account and voids its open links. Returns the number of claims freed. */
    private function dropReservationOf(int $userId): int
    {
        $freed = $this->db->table('account_email_claims')->where('user_id', $userId)->where('kind', 'pending')->delete();
        $this->db->table('users')->where('id', $userId)
            ->update(['pending_email' => null, 'pending_email_bidx' => null, 'pending_email_reserved_until' => null]);
        $this->db->table('email_confirmations')->where('user_id', $userId)->whereNull('used_at')->update(['used_at' => Date::now()]);

        return $freed;
    }
}
