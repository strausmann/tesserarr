// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

type Listener = () => void;

/** A matchMedia stand-in whose dark preference a test can flip. */
function stubMatchMedia(dark: boolean) {
    const listeners = new Set<Listener>();
    const query = {
        matches: dark,
        addEventListener: vi.fn((_: string, l: Listener) => listeners.add(l)),
        removeEventListener: vi.fn((_: string, l: Listener) =>
            listeners.delete(l),
        ),
    };
    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => query),
    );

    return {
        query,
        prefer(value: boolean) {
            query.matches = value;
            listeners.forEach((l) => l());
        },
        get listeners() {
            return listeners.size;
        },
    };
}

const theme = () => import('@/lib/theme.svelte');
const cookie = (name: string) =>
    document.cookie
        .split('; ')
        .find((c) => c.startsWith(`${name}=`))
        ?.split('=')[1];

beforeEach(() => {
    vi.resetModules();
    localStorage.clear();
    document.cookie = 'appearance=; max-age=0; path=/';
    document.documentElement.className = '';
    document.documentElement.style.colorScheme = '';
});

afterEach(() => {
    vi.unstubAllGlobals();
});

describe('initializeTheme', () => {
    it('follows the system on the first visit and stores no choice', async () => {
        stubMatchMedia(false);
        const { initializeTheme, themeState } = await theme();

        initializeTheme();

        expect(themeState().appearance.value).toBe('system');
        expect(localStorage.getItem('appearance')).toBeNull();
        expect(cookie('appearance')).toBeUndefined();
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('uses the default theme of the instance until the person chooses', async () => {
        stubMatchMedia(false);
        document.documentElement.dataset.themeDefault = 'dark';
        const { initializeTheme, themeState } = await theme();

        initializeTheme();

        expect(themeState().appearance.value).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        // A default is not a choice: nothing is stored, so a changed default reaches the person.
        expect(localStorage.getItem('appearance')).toBeNull();
        expect(cookie('appearance')).toBeUndefined();
        delete document.documentElement.dataset.themeDefault;
    });

    it('prefers the stored choice to the default of the instance', async () => {
        stubMatchMedia(false);
        document.documentElement.dataset.themeDefault = 'dark';
        localStorage.setItem('appearance', 'light');
        const { initializeTheme, themeState } = await theme();

        initializeTheme();

        expect(themeState().appearance.value).toBe('light');
        delete document.documentElement.dataset.themeDefault;
    });

    it('ignores a default it does not know', async () => {
        stubMatchMedia(false);
        document.documentElement.dataset.themeDefault = 'purple';
        const { initializeTheme, themeState } = await theme();

        initializeTheme();

        expect(themeState().appearance.value).toBe('system');
        delete document.documentElement.dataset.themeDefault;
    });

    it('applies a stored "dark" and keeps it', async () => {
        stubMatchMedia(false);
        localStorage.setItem('appearance', 'dark');
        const { initializeTheme, themeState } = await theme();

        initializeTheme();

        expect(themeState().appearance.value).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);
        expect(document.documentElement.style.colorScheme).toBe('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
    });

    it('treats an unknown stored value as "system"', async () => {
        stubMatchMedia(true);
        localStorage.setItem('appearance', 'purple');
        const { initializeTheme, themeState } = await theme();

        initializeTheme();

        expect(themeState().appearance.value).toBe('system');
        expect(themeState().resolvedAppearance()).toBe('dark');
    });

    it('follows a change of the system preference while on "system"', async () => {
        const media = stubMatchMedia(false);
        const { initializeTheme } = await theme();
        initializeTheme();

        media.prefer(true);

        expect(document.documentElement.classList.contains('dark')).toBe(true);
    });

    it('removes its listener when the returned function is called, and on a second start', async () => {
        const media = stubMatchMedia(false);
        const { initializeTheme } = await theme();

        initializeTheme();
        const stop = initializeTheme();
        expect(media.listeners).toBe(1);

        stop();
        expect(media.listeners).toBe(0);
        stop();
        expect(media.query.removeEventListener).toHaveBeenCalledTimes(2);
    });
});

describe('updateAppearance', () => {
    it('round-trips the choice through localStorage and the cookie', async () => {
        stubMatchMedia(false);
        const { updateAppearance } = await theme();

        updateAppearance('dark');
        expect(localStorage.getItem('appearance')).toBe('dark');
        expect(cookie('appearance')).toBe('dark');
        expect(document.documentElement.classList.contains('dark')).toBe(true);

        updateAppearance('light');
        expect(localStorage.getItem('appearance')).toBe('light');
        expect(cookie('appearance')).toBe('light');
        expect(document.documentElement.classList.contains('dark')).toBe(false);
    });

    it('is reachable through themeState', async () => {
        stubMatchMedia(true);
        const { themeState } = await theme();
        const state = themeState();

        expect(state.resolvedAppearance()).toBe('dark');
        state.updateAppearance('light');
        expect(state.appearance.value).toBe('light');
        expect(state.resolvedAppearance()).toBe('light');
    });
});

describe('applyThemeColor', () => {
    it('gives the browser bar the colour of the chosen appearance', async () => {
        document.head.innerHTML =
            '<meta name="theme-color" media="(prefers-color-scheme: light)" content="#fdfcf9">' +
            '<meta name="theme-color" media="(prefers-color-scheme: dark)" content="#201e1b">';
        const { applyThemeColor, THEME_COLORS } =
            await import('./theme.svelte');
        applyThemeColor(true);
        const colors = [
            ...document.querySelectorAll('meta[name="theme-color"]'),
        ].map((m) => m.getAttribute('content'));
        expect(colors).toEqual([THEME_COLORS.dark, THEME_COLORS.dark]);
        applyThemeColor(false);
        expect(
            document
                .querySelector('meta[name="theme-color"]')
                ?.getAttribute('content'),
        ).toBe(THEME_COLORS.light);
    });
});
