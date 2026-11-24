// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { getInitials, initialsApi } from '@/lib/initials';

describe('getInitials', () => {
    it.each([
        [undefined, ''],
        ['', ''],
        ['   ', ''],
        ['ada', 'A'],
        ['ada lovelace', 'AL'],
        ['  grace   brewster murray hopper ', 'GH'],
        ['émile zola', 'ÉZ'],
    ])('turns %j into %j', (name, initials) => {
        expect(getInitials(name)).toBe(initials);
    });

    it('takes the first code point, not half of a surrogate pair', () => {
        expect(getInitials('𝒜da 𝒵oe')).toBe('𝒜𝒵');
    });

    it('is exposed through initialsApi', () => {
        expect(initialsApi().getInitials('alan turing')).toBe('AT');
    });
});
