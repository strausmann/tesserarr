<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * The cast of personal data at rest (users.email, users.pending_email,
 * users.pgp_public_key, identities.email): the column holds the ciphertext of
 * PiiEncrypter, the model the plain text. Null stays null.
 *
 * @implements CastsAttributes<string|null, string|null>
 */
final class EncryptedPii implements CastsAttributes
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function get(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        return is_string($value) ? app(PiiEncrypter::class)->decrypt($value) : null;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return app(PiiEncrypter::class)->encrypt((string) $value);
    }
}
