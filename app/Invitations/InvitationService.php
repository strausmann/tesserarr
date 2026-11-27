<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Invitations;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleGuard;
use App\Authorization\Roles;
use App\Identity\AttachReason;
use App\Identity\Identity;
use App\Identity\IdentityClaim;
use App\Identity\IdentityLinker;
use App\Identity\UsernameDeriver;
use App\Models\User;
use App\Settings\SettingKey;
use App\Settings\SettingsRegistry;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\UniqueConstraintViolationException;
use InvalidArgumentException;

/**
 * Invitations (Spec 9.1, 9.3): issue, revoke, show, redeem.
 *
 * - The token is 32 random bytes, base64url; only its SHA-256 is stored. The
 *   link names it in the fragment (/invite#<token>).
 * - Issuing needs `invitations.create`, a recovery invitation also
 *   `users.manage` and every permission the account holds (a recovery
 *   invitation is a way into that account). Revoking needs
 *   `invitations.revoke`. The permissions are read under the locks of
 *   RoleGuard, inside the transaction of the change.
 * - Redemption (redeem; reserve and complete of task 11 follow the same
 *   path) uses the invitation up with the one conditional UPDATE of
 *   InvitationStore and makes the account and its identity in the same
 *   savepoint. When the same identity redeems two invitations at once, the
 *   second savepoint fails on the unique index of identities; it is rolled
 *   back, so its invitation stays unused, and the existing account is
 *   signed in (Go audit #5.3, K18).
 */
final class InvitationService
{
    public const NOTE_MAX = 100;

    /**
     * A recovery invitation opens an existing account, an operator's
     * included. It is meant to be handed to its owner at once, so it is
     * short-lived: one day unless asked otherwise, three days at most
     * (Spec 9.3 names no value; the general setting is for access
     * invitations).
     */
    public const RECOVERY_DEFAULT_HOURS = 24;

    public const RECOVERY_MAX_HOURS = 72;

    /** Attempts when two new accounts derive the same username at once. */
    private const USERNAME_ATTEMPTS = 3;

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly InvitationStore $store,
        private readonly RoleGuard $guard,
        private readonly AuditLogger $audit,
        private readonly IdentityLinker $linker,
        private readonly UsernameDeriver $usernames,
        private readonly SettingsRegistry $settings,
    ) {}

    /**
     * @param  CarbonInterval|null  $ttl  how long it stays valid; the instance default (7 days) when null
     *
     * @throws PrivilegeEscalation when $by may not issue it
     * @throws InvalidArgumentException for a recovery invitation without account, or an access invitation with one
     */
    public function issue(Actor $by, InvitationKind $kind = InvitationKind::Access, ?User $forUser = null, ?CarbonInterval $ttl = null, ?string $note = null): IssuedInvitation
    {
        if (($kind === InvitationKind::Recovery) !== ($forUser !== null)) {
            throw new InvalidArgumentException('a recovery invitation names its account, an access invitation none');
        }
        if ($kind === InvitationKind::Recovery) {
            $ttl ??= CarbonInterval::hours(self::RECOVERY_DEFAULT_HOURS);
            if (CarbonImmutable::now()->add($ttl)->gt(CarbonImmutable::now()->addHours(self::RECOVERY_MAX_HOURS))) {
                throw new InvalidArgumentException('days');
            }
        }
        $ttl ??= CarbonInterval::days((int) $this->settings->value(SettingKey::InvitationDays));
        $note = self::note($note);

        $plain = self::newToken();
        $context = ['kind' => $kind->value, 'expires_at' => null];

        try {
            $invitation = $this->db->transaction(function () use ($by, $kind, $forUser, $ttl, $note, $plain, &$context): Invitation {
                $this->guard->lockAccounts([], [$by->user, $forUser]);
                $this->assertMay($by, $kind === InvitationKind::Recovery
                    ? [Permission::InvitationsCreate, Permission::UsersManage]
                    : [Permission::InvitationsCreate]);
                if ($forUser !== null) {
                    // A way into the account: only for who could give it every permission it holds.
                    $this->guard->lockPermissionsOf($forUser);
                    $this->guard->assertCanGrant($by, $forUser->grantedPermissions());
                }

                $invitation = new Invitation;
                $invitation->forceFill([
                    'token_hash' => Invitation::hashOf($plain),
                    'kind' => $kind,
                    'for_user_id' => $forUser?->getKey(),
                    'note' => $note,
                    'created_by_actor' => ['type' => $by->type, 'id' => $by->id, 'label' => $by->label],
                    'expires_at' => CarbonImmutable::now()->add($ttl),
                ])->save();

                $context['expires_at'] = $invitation->expires_at->toIso8601String();
                $context['invitation'] = (int) $invitation->getKey();
                $this->audit->record(AuditAction::InvitationIssued, $by, $forUser ?? $invitation, $context);

                return $invitation;
            }, 3);
        } catch (PrivilegeEscalation $refused) {
            $this->audit->record(AuditAction::InvitationIssued, $by, $forUser, $context, AuditOutcome::Denied);

            throw $refused;
        }

        return new IssuedInvitation($invitation, $plain, self::url($plain));
    }

    /**
     * @throws PrivilegeEscalation without `invitations.revoke`
     * @throws InvitationNotRevocable when it is unknown, redeemed or revoked
     */
    public function revoke(int $invitationId, Actor $by): void
    {
        $this->db->transaction(function () use ($invitationId, $by): void {
            $this->guard->lockAccounts([], [$by->user]);
            $this->assertMay($by, [Permission::InvitationsRevoke]);

            $row = $this->db->selectOne(
                'update invitations set revoked_at = now(), updated_at = now() where id = ? and redeemed_at is null and revoked_at is null returning id, kind, for_user_id',
                [$invitationId],
            );
            if ($row === null) {
                throw new InvitationNotRevocable;
            }

            $invitation = Invitation::query()->findOrFail($invitationId);
            $this->audit->record(AuditAction::InvitationRevoked, $by, $invitation->forUser ?? $invitation, [
                'invitation' => $invitationId,
                'kind' => (string) $row->kind,
            ]);
        }, 3);
    }

    /** The open invitation behind $plainToken, for the redemption page; null for any other. */
    public function find(#[\SensitiveParameter] string $plainToken): ?Invitation
    {
        if (! self::looksLikeAToken($plainToken)) {
            return null;
        }

        return Invitation::query()->open()->where('token_hash', Invitation::hashOf($plainToken))->with('forUser')->first();
    }

    /**
     * Redeems with an identity a sign-in method brought (OIDC, Plex,
     * header; tasks 13 to 15). A new account for an access invitation, the
     * invitation's account for a recovery one. Logs identity, method, time
     * and source address.
     *
     * @throws InvitationNotRedeemable
     */
    public function redeem(#[\SensitiveParameter] string $plainToken, IdentityClaim $claim, string $ip): RedeemedInvitation
    {
        $hash = Invitation::hashOf($plainToken);

        $result = $this->db->transaction(
            fn (): RedeemedInvitation|string => $this->consumeAndAttach($hash, null, $claim, $ip),
            3,
        );

        if (is_string($result)) {
            $this->audit->record(AuditAction::InvitationRedeemed, Actor::guest(), null, [
                'reason' => $result,
                'method' => $claim->method->value,
                'provider' => $claim->providerKey,
                'ip' => $ip,
            ], AuditOutcome::Denied);

            throw new InvitationNotRedeemable($result);
        }

        return $result;
    }

    /**
     * The common part of redeem and (task 11) complete (D5): consume the
     * invitation and attach the identity in one savepoint.
     *
     * @return RedeemedInvitation|string the result, or the reason it failed
     */
    private function consumeAndAttach(string $tokenHash, ?string $reservationHash, IdentityClaim $claim, string $ip): RedeemedInvitation|string
    {
        for ($attempt = 1; ; $attempt++) {
            try {
                return $this->db->transaction(function () use ($tokenHash, $reservationHash, $claim, $ip): RedeemedInvitation|string {
                    $id = $this->store->consume($tokenHash, $reservationHash);
                    if ($id === null) {
                        return $this->whyNot($tokenHash);
                    }

                    $invitation = Invitation::query()->findOrFail($id);
                    if ($invitation->kind === InvitationKind::Recovery) {
                        $user = $this->recoveryTarget($invitation);
                    } else {
                        $user = $this->newAccount($claim);
                    }

                    $identity = $this->linker->attach($user, $claim, AttachReason::Invited);

                    $this->db->table('invitations')->where('id', $id)->update([
                        'redeemed_by_user_id' => $user->getKey(),
                        'redeemed_ip' => $ip,
                    ]);
                    $this->recordRedemption($user, $invitation, $identity, $claim, $ip, consumed: true);

                    return new RedeemedInvitation($user, $invitation->kind);
                });
            } catch (IssuerNoLongerPermitted) {
                // The savepoint is gone, and with it the use of the invitation.
                return InvitationNotRedeemable::ISSUER_LACKS_PERMISSION;
            } catch (UniqueConstraintViolationException $collision) {
                // The savepoint is gone, and with it the use of the invitation.
                $existing = $this->linker->find($claim);
                if ($existing !== null) {
                    return $this->adopt($existing, $tokenHash, $claim, $ip);
                }
                // Not the identity: two new accounts derived the same username.
                if ($attempt >= self::USERNAME_ATTEMPTS) {
                    throw $collision;
                }
            }
        }
    }

    /** The identity already belongs to an account: sign that one in, leave the invitation unused. */
    private function adopt(Identity $identity, string $tokenHash, IdentityClaim $claim, string $ip): RedeemedInvitation|string
    {
        $invitation = Invitation::query()->where('token_hash', $tokenHash)->first();
        if ($invitation === null) {
            return InvitationNotRedeemable::UNKNOWN;
        }
        // A recovery invitation is for one account; the identity of another is no way in.
        if ($invitation->kind === InvitationKind::Recovery && $invitation->for_user_id !== $identity->user_id) {
            return InvitationNotRedeemable::IDENTITY_TAKEN;
        }

        if ($invitation->kind === InvitationKind::Recovery) {
            try {
                $this->recoveryTarget($invitation);
            } catch (IssuerNoLongerPermitted) {
                return InvitationNotRedeemable::ISSUER_LACKS_PERMISSION;
            }
        }

        $user = $identity->user;
        $this->recordRedemption($user, $invitation, $identity, $claim, $ip, consumed: false);

        return new RedeemedInvitation($user, $invitation->kind, consumed: false);
    }

    /**
     * The account of a recovery invitation, with the check of its issue
     * made again now: the issuer still exists, is not locked, still holds
     * the permissions to issue it and could still give every permission the
     * account holds now. Otherwise an old link would open an account that
     * has gained permissions since, or one its issuer may no longer reach.
     * Both accounts are locked FOR SHARE first (accounts before roles, the
     * order of RoleGuard), so a concurrent change of either waits.
     *
     * @throws IssuerNoLongerPermitted
     */
    private function recoveryTarget(Invitation $invitation): User
    {
        $target = User::query()->findOrFail($invitation->for_user_id);
        $by = $invitation->created_by_actor;

        if ($by['type'] === Actor::CLI) {
            // The operator's console stands outside the accounts (Actor::cli).
            $this->guard->lockAccounts([], [$target]);

            return $target;
        }

        $issuer = in_array($by['type'], [Actor::USER, Actor::TOKEN], true) && $by['id'] !== null
            ? User::query()->find($by['id'])
            : null;
        if ($issuer === null) {
            throw new IssuerNoLongerPermitted;
        }

        $this->guard->lockAccounts([], [$issuer, $target]);
        try {
            $actor = Actor::user($issuer);
            $this->assertMay($actor, [Permission::InvitationsCreate, Permission::UsersManage]);
            $this->guard->lockPermissionsOf($target);
            $this->guard->assertCanGrant($actor, $target->grantedPermissions());
        } catch (PrivilegeEscalation) {
            throw new IssuerNoLongerPermitted;
        }

        return $target;
    }

    private function newAccount(IdentityClaim $claim): User
    {
        $preferred = $claim->username ?? $claim->displayName ?? $claim->email ?? '';
        $username = $this->usernames->derive($preferred);

        $user = new User;
        $user->forceFill([
            'username' => $username,
            'display_name' => self::displayName($claim->displayName) ?? $username,
        ])->save();
        $user->assignRole(Roles::USER);

        return $user;
    }

    private function recordRedemption(User $user, Invitation $invitation, Identity $identity, IdentityClaim $claim, string $ip, bool $consumed): void
    {
        $this->audit->record(AuditAction::InvitationRedeemed, Actor::user($user), $user, [
            'invitation' => (int) $invitation->getKey(),
            'kind' => $invitation->kind->value,
            'identity' => (int) $identity->getKey(),
            'method' => $claim->method->value,
            'provider' => $claim->providerKey,
            'ip' => $ip,
            'consumed' => $consumed,
            'reason' => $consumed ? AttachReason::Invited->value : 'existing_identity',
        ]);
    }

    /** Why nothing matched: read after the UPDATE, for the log only. */
    private function whyNot(string $tokenHash): string
    {
        $row = $this->db->selectOne(
            'select redeemed_at is not null as redeemed, revoked_at is not null as revoked, expires_at <= now() as expired from invitations where token_hash = ?',
            [$tokenHash],
        );

        return match (true) {
            $row === null => InvitationNotRedeemable::UNKNOWN,
            (bool) $row->revoked => InvitationNotRedeemable::REVOKED,
            (bool) $row->redeemed => InvitationNotRedeemable::REDEEMED,
            (bool) $row->expired => InvitationNotRedeemable::EXPIRED,
            default => InvitationNotRedeemable::RESERVED,
        };
    }

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws PrivilegeEscalation
     */
    private function assertMay(Actor $by, array $permissions): void
    {
        $held = $this->guard->grantableBy($by);
        $missing = array_values(array_filter($permissions, fn (Permission $p) => ! in_array($p, $held, true)));
        if ($missing !== []) {
            throw new PrivilegeEscalation($missing);
        }
    }

    public static function url(#[\SensitiveParameter] string $plainToken): string
    {
        return url('/invite').'#'.$plainToken;
    }

    private static function newToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /** 43 characters of base64url: anything else is no token of ours, and is not looked up. */
    public static function looksLikeAToken(string $value): bool
    {
        return preg_match('/\A[A-Za-z0-9_-]{43}\z/', $value) === 1;
    }

    private static function note(?string $note): ?string
    {
        if ($note === null) {
            return null;
        }
        $note = trim($note);
        if (! mb_check_encoding($note, 'UTF-8') || mb_strlen($note) > self::NOTE_MAX || preg_match('/\p{C}/u', $note) === 1) {
            throw new InvalidArgumentException('note');
        }

        return $note === '' ? null : $note;
    }

    private static function displayName(?string $name): ?string
    {
        if ($name === null) {
            return null;
        }
        $name = trim((string) preg_replace('/\p{C}+/u', '', $name));

        return $name === '' ? null : mb_substr($name, 0, 100);
    }
}
