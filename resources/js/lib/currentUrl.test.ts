// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { beforeEach, describe, expect, it, vi } from 'vitest';

const page = vi.hoisted(() => ({ url: '/' }));
vi.mock('@inertiajs/svelte', () => ({ page }));

const { currentUrlState } = await import('@/lib/currentUrl.svelte');

beforeEach(() => {
    page.url = '/';
});

describe('currentUrlState', () => {
    it('reduces the page url to its path', () => {
        page.url = '/settings/profile?tab=a#top';

        expect(currentUrlState().currentUrl).toBe('/settings/profile');
    });

    it('falls back to the raw url when it cannot be parsed', () => {
        page.url = 'http://[broken';

        expect(currentUrlState().currentUrl).toBe('http://[broken');
    });

    it('matches the current url exactly, for strings and route objects', () => {
        const state = currentUrlState();

        expect(state.isCurrentUrl('/settings', '/settings')).toBe(true);
        expect(state.isCurrentUrl('/settings', '/settings/profile')).toBe(
            false,
        );
        expect(
            state.isCurrentUrl(
                { url: '/settings', method: 'get' },
                '/settings',
            ),
        ).toBe(true);
    });

    it('matches a parent url as well', () => {
        const state = currentUrlState();

        expect(
            state.isCurrentOrParentUrl('/settings', '/settings/profile'),
        ).toBe(true);
        expect(state.isCurrentOrParentUrl('/dashboard', '/settings')).toBe(
            false,
        );
    });

    it('picks the value for the current url', () => {
        const state = currentUrlState();

        expect(state.whenCurrentUrl('/a', '/a', 'yes', 'no')).toBe('yes');
        expect(state.whenCurrentUrl('/a', '/b', 'yes', 'no')).toBe('no');
    });
});
