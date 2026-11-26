// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { beforeEach, describe, expect, it, test, vi } from 'vitest';

const props = vi.hoisted(() => ({
    value: {} as Record<string, unknown>,
}));
vi.mock('@inertiajs/svelte', () => ({
    page: {
        get props() {
            return props.value;
        },
    },
}));

const {
    applyDocumentLanguage,
    interpolate,
    locale,
    reportMissing,
    resetReported,
    t,
    translate,
} = await import('./i18n');

const readLang = (name: string): Record<string, string> =>
    JSON.parse(
        // Vitest runs from the repository root.
        readFileSync(resolve(process.cwd(), 'lang', `${name}.json`), 'utf8'),
    );

describe('lang files', () => {
    const en = readLang('en');
    const de = readLang('de');

    it('keeps en.json and de.json key-identical', () => {
        expect(Object.keys(de).sort()).toEqual(Object.keys(en).sort());
    });

    it('gives every key a string in both languages', () => {
        for (const lines of [en, de]) {
            for (const [key, line] of Object.entries(lines)) {
                expect(typeof line, key).toBe('string');
                expect(line.trim(), key).not.toBe('');
            }
        }
    });

    it('uses the same placeholders in both languages', () => {
        const names = (line: string) =>
            [...line.matchAll(/:([a-z_]+)/g)].map((m) => m[1]).sort();
        for (const key of Object.keys(en)) {
            expect(names(de[key]), key).toEqual(names(en[key]));
        }
    });
});

describe('translate', () => {
    beforeEach(() => resetReported());

    it('returns the string of the key', () => {
        expect(translate({ 'nav.home': 'Startseite' }, 'nav.home')).toBe(
            'Startseite',
        );
    });

    it('puts the params into the string', () => {
        expect(
            translate({ greet: ':name has :count new' }, 'greet', {
                name: 'Alex',
                count: 3,
            }),
        ).toBe('Alex has 3 new');
    });

    it('replaces the longer placeholder first', () => {
        expect(
            interpolate(':count of :countdown', { count: 1, countdown: 9 }),
        ).toBe('1 of 9');
    });

    test('falls back to the key and reports it once', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        expect(translate({}, 'nav.nowhere')).toBe('nav.nowhere');
        expect(translate({}, 'nav.nowhere')).toBe('nav.nowhere');
        expect(translate({}, 'nav.elsewhere')).toBe('nav.elsewhere');

        expect(warn).toHaveBeenCalledTimes(2);
        expect(warn).toHaveBeenNthCalledWith(
            1,
            'i18n: no string for "nav.nowhere"',
        );
    });

    it('reports through the given reporter', () => {
        const report = vi.fn();
        translate({}, 'missing', undefined, report);
        expect(report).toHaveBeenCalledWith('missing');
    });

    it('reports a key only once, also through reportMissing', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
        reportMissing('a');
        reportMissing('a');
        expect(warn).toHaveBeenCalledOnce();
    });
});

describe('t', () => {
    it('reads the strings of the page', () => {
        props.value = {
            locale: 'de',
            translations: { 'nav.home': 'Startseite' },
        };
        expect(t('nav.home')).toBe('Startseite');
        expect(locale()).toBe('de');
    });

    it('shows the key while the page has no strings', () => {
        vi.spyOn(console, 'warn').mockImplementation(() => {});
        props.value = {};
        expect(t('nav.home')).toBe('nav.home');
        expect(locale()).toBe('en');
    });
});

describe('applyDocumentLanguage', () => {
    it('sets the lang attribute to the language of the page', () => {
        const root = document.createElement('html');
        root.lang = 'de';
        applyDocumentLanguage('en', root);
        expect(root.lang).toBe('en');
    });

    it('leaves the attribute alone without a language', () => {
        const root = document.createElement('html');
        root.lang = 'de';
        applyDocumentLanguage(undefined, root);
        applyDocumentLanguage('', root);
        expect(root.lang).toBe('de');
    });
});
