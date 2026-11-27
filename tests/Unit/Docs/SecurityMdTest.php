<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

// SECURITY.md follows one schema in every project of the maintainer: one
// address, one published key. The repository is public, so the policy names
// GitHub private vulnerability reporting as a second channel.

function securityPolicy(): string
{
    $path = dirname(__DIR__, 3).'/SECURITY.md';

    return is_file($path) ? (string) file_get_contents($path) : '';
}

it('names the security address and fingerprint from the schema', function () {
    $policy = securityPolicy();

    expect($policy)->toContain('security@strausmann.ch')
        ->and($policy)->toContain('3EE0 4713 5125 2BF2 C6BE FB1B BB40 F221 7A21 F210')
        ->and($policy)->toContain('.well-known/security.asc')
        ->and($policy)->toContain('GitHub private vulnerability reporting');
});

it('ships the public key the policy links to', function () {
    $key = (string) @file_get_contents(dirname(__DIR__, 3).'/.well-known/security.asc');

    expect($key)->toStartWith('-----BEGIN PGP PUBLIC KEY BLOCK-----')
        ->and(trim($key))->toEndWith('-----END PGP PUBLIC KEY BLOCK-----');
});
