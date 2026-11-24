// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { describe, expect, it } from 'vitest';
import { cn, toUrl } from '@/lib/utils';

describe('cn', () => {
    it('lets the later of two conflicting tailwind classes win', () => {
        expect(cn('p-2', 'p-4')).toBe('p-4');
    });

    it('drops falsy values', () => {
        expect(cn('a', false, undefined, null, 'b')).toBe('a b');
    });
});

describe('toUrl', () => {
    it('returns a string href unchanged', () => {
        expect(toUrl('/dashboard')).toBe('/dashboard');
    });

    it('returns the url of a route object', () => {
        expect(toUrl({ url: '/settings', method: 'get' })).toBe('/settings');
    });
});
