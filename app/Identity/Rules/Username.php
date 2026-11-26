<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Normalizer;

/**
 * A username: 3 to 64 characters, lower case, in Unicode NFC, made of
 * letters, digits, dot, underscore and dash. Never an "@": a username must
 * never look like an e-mail address, or the sign-in name "username or
 * e-mail" would be ambiguous (Spec 16.16).
 *
 * The rule checks the stored form. Input is brought into it first with
 * Username::normalise() (lower case, NFC, trimmed), so "Max" and "max" are
 * the same name.
 *
 * Applies wherever a username comes in: redeeming an invitation (task 10),
 * renaming, the command line and the API.
 */
final class Username implements ValidationRule
{
    public const MIN = 3;

    public const MAX = 64;

    private const PATTERN = '/\A[\p{Ll}\p{Lo}\p{Lm}\p{Mn}\p{Nd}._-]+\z/u';

    public static function normalise(string $value): string
    {
        $nfc = Normalizer::normalize(trim($value), Normalizer::FORM_C);

        return mb_strtolower($nfc === false ? trim($value) : $nfc);
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('validation.username.format')->translate();

            return;
        }

        if (str_contains($value, '@')) {
            $fail('validation.username.at_sign')->translate();

            return;
        }

        $length = mb_strlen($value);
        if ($length < self::MIN || $length > self::MAX) {
            $fail('validation.username.length')->translate(['min' => self::MIN, 'max' => self::MAX]);

            return;
        }

        if (Normalizer::normalize($value, Normalizer::FORM_C) !== $value
            || mb_strtolower($value) !== $value
            || preg_match(self::PATTERN, $value) !== 1) {
            $fail('validation.username.format')->translate();
        }
    }
}
