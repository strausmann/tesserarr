<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Encryption\Encrypter;

/**
 * Encrypts personal data with TESSERARR_PII_KEY (AES-256-GCM, authenticated)
 * and decrypts with that key and every previous one (PiiKeys). It is a
 * container service of its own, not Model::encryptUsing(): that would switch
 * the one encrypter of every `encrypted` cast, the API keys of the
 * connections included (Laravel HasAttributes::encryptUsing).
 *
 * Writes only in the constructor; the keys are fixed for the process.
 */
final class PiiEncrypter
{
    public const CIPHER = 'aes-256-gcm';

    private readonly Encrypter $all;

    private readonly Encrypter $current;

    public function __construct(PiiKeys $keys)
    {
        $this->current = new Encrypter($keys->key, self::CIPHER);
        $this->all = (new Encrypter($keys->key, self::CIPHER))->previousKeys($keys->previous);
    }

    public function encrypt(string $plain): string
    {
        return $this->current->encryptString($plain);
    }

    /** @throws DecryptException */
    public function decrypt(string $payload): string
    {
        return $this->all->decryptString($payload);
    }

    /** Was $payload written with the current key (and not with a previous one)? */
    public function isCurrent(string $payload): bool
    {
        try {
            $this->current->decryptString($payload);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
