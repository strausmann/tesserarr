<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Identity\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * The name an account is shown with: up to 255 characters, no control or
 * formatting characters (no terminal escape sequences, no bidi overrides).
 * Empty means "show the username".
 */
final class DisplayName implements ValidationRule
{
    public const MAX = 255;

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || mb_strlen($value) > self::MAX || preg_match('/\p{C}/u', $value) !== 0) {
            $fail('validation.display_name.format')->translate(['max' => self::MAX]);
        }
    }
}
