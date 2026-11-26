<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity;

use App\Identity\Rules\Username;
use Illuminate\Database\ConnectionInterface;

/**
 * Proposes a free username from what a sign-in provider sends, for example
 * the `preferred_username` of OIDC. Providers often send an e-mail-shaped
 * value (Entra ID sends the UPN): only the part before the first "@" is used,
 * so a username never looks like an address (Spec 16.16, Go audit #6/#7).
 *
 * The result is brought into the username form (lower case, NFC, allowed
 * characters only), padded to the minimum length and numbered when taken:
 * "bob", "bob2", "bob3" ...
 */
final class UsernameDeriver
{
    private const FALLBACK = 'user';

    public function __construct(private readonly ConnectionInterface $db) {}

    public function derive(string $preferred): string
    {
        $local = explode('@', $preferred, 2)[0];
        $base = (string) preg_replace('/[^\p{Ll}\p{Lo}\p{Lm}\p{Mn}\p{Nd}._-]+/u', '', Username::normalise($local));
        $base = trim($base, '.-_');

        if ($base === '') {
            $base = self::FALLBACK;
        } elseif (mb_strlen($base) < Username::MIN) {
            $base .= '-'.self::FALLBACK;
        }

        $base = mb_substr($base, 0, Username::MAX - 6);

        $candidate = $base;
        for ($n = 2; $this->taken($candidate); $n++) {
            $candidate = $base.$n;
        }

        return $candidate;
    }

    private function taken(string $username): bool
    {
        return $this->db->table('users')->where('username', $username)->exists();
    }
}
