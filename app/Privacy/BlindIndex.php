<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

/**
 * Finds an address without storing it: HMAC-SHA256 of the normalised address
 * (EmailNormalizer) with TESSERARR_BLIND_INDEX_KEY, 64 hex characters. The
 * same address in another spelling has the same index; a database dump
 * without the key reveals nothing, and the key is not the encryption key.
 */
final class BlindIndex
{
    public static function of(string $email): string
    {
        return self::with($email, app(PiiKeys::class)->blindIndex);
    }

    /**
     * Every index the address may still be stored under: the current one
     * first, and while the blind index key changes (TESSERARR_BLIND_INDEX_PREVIOUS_KEY
     * set, `tesserarr:pii:rotate` not finished) also the previous one.
     *
     * @return list<string>
     */
    public static function candidates(string $email): array
    {
        $keys = app(PiiKeys::class);
        $current = self::with($email, $keys->blindIndex);

        return $keys->blindIndexPrevious === null ? [$current] : [$current, self::with($email, $keys->blindIndexPrevious)];
    }

    /** The index under a given key (the rotation computes old and new). */
    public static function with(string $email, string $key): string
    {
        return hash_hmac('sha256', EmailNormalizer::normalize($email), $key);
    }
}
