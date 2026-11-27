<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Tokens;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use App\Audit\AuditOutcome;
use App\Authorization\Permission;
use App\Authorization\PrivilegeEscalation;
use App\Authorization\RoleGuard;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\ConnectionInterface;
use Laravel\Sanctum\NewAccessToken;

/**
 * Issues API tokens (Spec 9.5, ADR-0007 point 6).
 *
 * - The owner is always a user.
 * - The permissions are a selection from the catalogue, never empty and never
 *   a wildcard, and never more than the issuer may grant
 *   (RoleGuard::assertCanGrant): in the interface and on the API the issuer
 *   is the owner, so the ceiling is what they hold; on the console the issuer
 *   names an account, and the ceiling is what that account holds.
 *   `satellite.execute` never goes on a user token.
 * - Stored as a SHA-256 hash (Sanctum); the plain text exists only in the
 *   returned NewAccessToken, to be shown once.
 * - Issue and refusal are in the audit log, with the token's id, name and
 *   permissions, never the token.
 *
 * At run time the Authorizer caps a token at what its owner holds then, so a
 * demotion takes a token's extra permissions away at once.
 */
final class TokenIssuer
{
    /** The start of every token secret, so secret scanners recognise one (config/sanctum.php). */
    public const PREFIX = 'tsr_';

    public const NAME_MAX = 100;

    public function __construct(
        private readonly RoleGuard $guard,
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @param  list<Permission>  $permissions
     *
     * @throws InvalidTokenRequest
     * @throws PrivilegeEscalation
     */
    public function issue(User $owner, Actor $by, string $name, array $permissions, ?CarbonImmutable $expiresAt = null): NewAccessToken
    {
        self::assertMayIssueFor($owner, $by);
        $name = self::name($name);
        $permissions = Permission::ordered($permissions);
        if ($permissions === []) {
            throw new InvalidTokenRequest(InvalidTokenRequest::PERMISSIONS);
        }
        if ($expiresAt !== null && ! $expiresAt->isFuture()) {
            throw new InvalidTokenRequest(InvalidTokenRequest::EXPIRY);
        }

        $context = [
            'label' => $name,
            'abilities' => Permission::values($permissions),
            'owner' => (int) $owner->getKey(),
            'expires_at' => $expiresAt?->toIso8601String(),
        ];

        try {
            return $this->db->transaction(function () use ($owner, $by, $name, $permissions, $expiresAt, $context): NewAccessToken {
                // Accounts first, in id order, then their roles (the lock order
                // of every guarded change); the ceiling is read after the locks.
                $this->guard->lockAccounts([], [$by->user, $owner]);
                $this->guard->assertCanGrant($by, $permissions, $owner);

                $token = $owner->createToken($name, Permission::values($permissions), $expiresAt);
                $this->audit->record(AuditAction::TokenIssued, $by, $token->accessToken, $context);

                return $token;
            }, 3);
        } catch (PrivilegeEscalation $refused) {
            $this->audit->record(AuditAction::TokenIssued, $by, $owner, $context, AuditOutcome::Denied);

            throw $refused;
        }
    }

    /** In the interface and on the API only for oneself; a satellite issues no user token. */
    private static function assertMayIssueFor(User $owner, Actor $by): void
    {
        $allowed = match ($by->type) {
            Actor::CLI => true,
            Actor::USER, Actor::TOKEN => $by->user !== null && $by->user->is($owner),
            Actor::SATELLITE, Actor::GUEST => false,
        };

        if (! $allowed) {
            throw new InvalidTokenRequest(InvalidTokenRequest::OWNER);
        }
    }

    /** Valid UTF-8, trimmed; 1 to NAME_MAX characters, no control or format characters. */
    private static function name(string $name): string
    {
        if (! mb_check_encoding($name, 'UTF-8')) {
            throw new InvalidTokenRequest(InvalidTokenRequest::NAME);
        }
        $name = trim($name);

        if ($name === '' || mb_strlen($name) > self::NAME_MAX || preg_match('/\p{C}/u', $name) === 1) {
            throw new InvalidTokenRequest(InvalidTokenRequest::NAME);
        }

        return $name;
    }
}
