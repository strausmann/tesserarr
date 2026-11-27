<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use App\Privacy\BlindIndex;
use App\Privacy\EmailNormalizer;
use App\Privacy\PiiKeys;

/*
| The blind index finds an address without storing it: HMAC-SHA256 over the
| normalised address with a key of its own (TESSERARR_BLIND_INDEX_KEY). Two
| spellings of one address get one index; two addresses never do.
*/

it('normalises an address: trimmed, NFC, lower case, domain as ASCII', function (string $input, string $normalised) {
    expect(EmailNormalizer::normalize($input))->toBe($normalised);
})->with([
    'spaces around' => ["  alice@example.org \n", 'alice@example.org'],
    'upper case everywhere' => ['Alice.Example@Example.ORG', 'alice.example@example.org'],
    'unicode local part in NFD' => ["j\u{0065}\u{0301}r\u{00F4}me@example.org", "j\u{00E9}r\u{00F4}me@example.org"],
    'IDN domain' => ["bob@b\u{00FC}cher.test", 'bob@xn--bcher-kva.test'],
    'IDN domain in upper case' => ["bob@B\u{00DC}CHER.test", 'bob@xn--bcher-kva.test'],
]);

it('finds the same index regardless of case, spaces and unicode form', function () {
    $nfc = "J\u{00C9}r\u{00F4}me@B\u{00DC}cher.test";
    $nfd = " j\u{0065}\u{0301}r\u{006F}\u{0302}me@b\u{0075}\u{0308}cher.TEST ";

    expect(BlindIndex::of($nfc))->toBe(BlindIndex::of($nfd))
        ->toBe(hash_hmac('sha256', "j\u{00E9}r\u{00F4}me@xn--bcher-kva.test", (string) config('tesserarr.pii.blind_index_key')))
        ->toHaveLength(64);
});

it('gives different addresses different indexes', function () {
    expect(BlindIndex::of('alice@example.org'))->not->toBe(BlindIndex::of('alice@example.net'));
});

it('depends on the blind index key', function () {
    $before = BlindIndex::of('alice@example.org');
    config(['tesserarr.pii.blind_index_key' => str_repeat('z', 32)]);
    app()->forgetInstance(PiiKeys::class);

    expect(BlindIndex::of('alice@example.org'))->not->toBe($before);
});
