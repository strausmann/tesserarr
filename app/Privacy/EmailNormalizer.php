<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Normalizer;

/**
 * One spelling per address, the input of the blind index: trimmed, Unicode
 * NFC, the whole address in lower case, the domain in ASCII (IDNA). The local
 * part is lower-cased as well, although RFC 5321 lets it be case-sensitive:
 * no mail system the project targets treats it so, and "Alice@" and "alice@"
 * must not be two accounts.
 */
final class EmailNormalizer
{
    /**
     * What counts as an address in text (logs, audit context): a local part,
     * "@", and a domain with at least one dot. Letters of every script count.
     */
    public const PATTERN = '/[\p{L}\p{N}._%+\-]+@[\p{L}\p{N}\-]+(?:\.[\p{L}\p{N}\-]+)+/u';

    public static function normalize(string $address): string
    {
        $address = trim($address);
        $address = Normalizer::normalize($address, Normalizer::FORM_C) ?: $address;
        $address = mb_strtolower($address, 'UTF-8');

        $at = strrpos($address, '@');
        if ($at === false) {
            return $address;
        }

        $domain = substr($address, $at + 1);
        $ascii = idn_to_ascii($domain, IDNA_NONTRANSITIONAL_TO_ASCII, INTL_IDNA_VARIANT_UTS46);

        return substr($address, 0, $at + 1).(is_string($ascii) && $ascii !== '' ? $ascii : $domain);
    }

    /** Does $text contain something that looks like an address? */
    public static function containsAddress(string $text): bool
    {
        return preg_match(self::PATTERN, $text) === 1;
    }
}
