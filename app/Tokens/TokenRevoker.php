<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Tokens;

use App\Audit\Actor;
use App\Audit\AuditAction;
use App\Audit\AuditLogger;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Revokes an API token: the row is deleted, so the next request with it is
 * refused (Sanctum finds no token), and the revocation is logged in the same
 * transaction. Who may revoke which token is decided before, by the caller,
 * through the Authorizer (`tokens.manage_own` for the own, `tokens.manage_all`
 * for any).
 */
final class TokenRevoker
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly ConnectionInterface $db,
    ) {}

    /**
     * @throws ModelNotFoundException when the token is already gone
     */
    public function revoke(PersonalAccessToken $token, Actor $by): void
    {
        $this->db->transaction(function () use ($token, $by): void {
            /** @var PersonalAccessToken|null $locked */
            $locked = PersonalAccessToken::query()->whereKey($token->getKey())->lockForUpdate()->first();
            if ($locked === null) {
                throw (new ModelNotFoundException)->setModel(PersonalAccessToken::class, [$token->getKey()]);
            }

            $locked->delete();
            $this->audit->record(AuditAction::TokenRevoked, $by, $locked, [
                'label' => (string) $locked->getAttribute('name'),
                'owner' => (int) $locked->getAttribute('tokenable_id'),
            ]);
        }, 3);
    }
}
