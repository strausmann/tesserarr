<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

/**
 * The keys for personal data, read from the environment
 * (config/tesserarr.php, `pii`) and never from the database:
 *
 * - TESSERARR_PII_KEY encrypts the addresses and the PGP key (32 bytes);
 *   TESSERARR_PII_PREVIOUS_KEYS (comma-separated) still decrypts what an
 *   older key wrote, until `tesserarr:pii:rotate` rewrote it;
 * - TESSERARR_BLIND_INDEX_KEY keys the HMAC of the blind index (at least 32
 *   bytes); TESSERARR_BLIND_INDEX_PREVIOUS_KEY tells the rotation that the
 *   index has to be computed again.
 *
 * A key is written raw or as "base64:…", like APP_KEY. It must not be APP_KEY:
 * a leaked APP_KEY (it signs cookies and sits in every .env) must not open
 * the addresses as well.
 */
final readonly class PiiKeys
{
    public const KEY_BYTES = 32;

    /**
     * @param  list<string>  $previous
     */
    private function __construct(
        public string $key,
        public array $previous,
        public string $blindIndex,
        public ?string $blindIndexPrevious,
    ) {}

    /**
     * @param  array<string, mixed>  $pii  the `tesserarr.pii` configuration
     *
     * @throws PiiKeyRefused
     */
    public static function fromConfig(array $pii, ?string $appKey): self
    {
        $raw = self::text($pii['key'] ?? null);
        if ($raw === '') {
            throw new PiiKeyRefused('pii_key_missing');
        }

        $key = self::decode($raw);
        if (strlen($key) !== self::KEY_BYTES) {
            throw new PiiKeyRefused('pii_key_invalid');
        }

        $app = self::text($appKey) === '' ? null : self::decode(self::text($appKey));
        if ($app !== null && hash_equals($app, $key)) {
            throw new PiiKeyRefused('pii_key_is_app_key');
        }

        $previous = [];
        foreach (explode(',', self::text($pii['previous_keys'] ?? null)) as $entry) {
            if (trim($entry) === '') {
                continue;
            }
            $decoded = self::decode(trim($entry));
            if (strlen($decoded) !== self::KEY_BYTES) {
                throw new PiiKeyRefused('pii_previous_key_invalid');
            }
            $previous[] = $decoded;
        }

        $blind = self::text($pii['blind_index_key'] ?? null);
        if ($blind === '') {
            throw new PiiKeyRefused('blind_index_key_missing');
        }
        $blind = self::decode($blind);
        if (strlen($blind) < self::KEY_BYTES) {
            throw new PiiKeyRefused('blind_index_key_invalid');
        }
        if (hash_equals($key, $blind) || ($app !== null && hash_equals($app, $blind))) {
            throw new PiiKeyRefused('blind_index_key_reused');
        }

        $blindPrevious = self::text($pii['blind_index_previous_key'] ?? null);

        return new self($key, $previous, $blind, $blindPrevious === '' ? null : self::decode($blindPrevious));
    }

    /** The bytes of a key written raw or as "base64:…". */
    public static function decode(string $written): string
    {
        if (str_starts_with($written, 'base64:')) {
            $bytes = base64_decode(substr($written, 7), true);

            return $bytes === false ? '' : $bytes;
        }

        return $written;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
