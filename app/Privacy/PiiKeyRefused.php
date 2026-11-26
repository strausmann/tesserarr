<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use RuntimeException;

/**
 * A key for personal data is missing or unusable (PiiKeys). The central
 * instance does not start like this: without its own key, addresses would be
 * unreadable or protected by APP_KEY alone. The message names the variable
 * and what is wrong, never a value.
 */
final class PiiKeyRefused extends RuntimeException
{
    private const MESSAGES = [
        'pii_key_missing' => 'TESSERARR_PII_KEY is not set: personal data cannot be encrypted without it.',
        'pii_key_invalid' => 'TESSERARR_PII_KEY must be 32 bytes, written raw or as "base64:" followed by 32 bytes in Base64.',
        'pii_key_is_app_key' => 'TESSERARR_PII_KEY must differ from APP_KEY: personal data has a key of its own.',
        'pii_previous_key_invalid' => 'TESSERARR_PII_PREVIOUS_KEYS holds a key that is not 32 bytes.',
        'blind_index_key_missing' => 'TESSERARR_BLIND_INDEX_KEY is not set: addresses cannot be found without it.',
        'blind_index_key_invalid' => 'TESSERARR_BLIND_INDEX_KEY must be at least 32 bytes.',
        'blind_index_key_reused' => 'TESSERARR_BLIND_INDEX_KEY must differ from TESSERARR_PII_KEY and APP_KEY.',
    ];

    /**
     * @param  key-of<self::MESSAGES>  $reason
     */
    public function __construct(public readonly string $reason)
    {
        parent::__construct(self::MESSAGES[$reason]);
    }
}
