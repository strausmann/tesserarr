// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { page } from '@inertiajs/svelte';

/**
 * The interface in two languages. The server picks the language
 * (App\Http\Middleware\SetLocale: profile, then Accept-Language, then
 * English) and sends its strings as the Inertia prop `translations`, read
 * from lang/en.json or lang/de.json. Both files hold the same keys
 * (i18n.test.ts).
 *
 * A placeholder is written `:name`, as in Laravel. A key that has no string
 * shows the key itself, so a gap is visible, and is reported once per key.
 */
export type Translations = Record<string, string>;
export type Params = Record<string, string | number>;
export type Report = (key: string) => void;

/**
 * Puts the params into the string in one pass, so a value that itself
 * contains `:name` is never replaced again. A name without a param stays.
 */
export function interpolate(line: string, params: Params = {}): string {
    return line.replace(/:([A-Za-z_]+)/g, (whole, name: string) =>
        Object.hasOwn(params, name) ? String(params[name]) : whole,
    );
}

/** The string of `key` in `lines`, or the key itself (and a report) when there is none. */
export function translate(
    lines: Translations,
    key: string,
    params?: Params,
    report: Report = reportMissing,
): string {
    const line = lines[key];
    if (typeof line !== 'string') {
        report(key);

        return key;
    }

    return interpolate(line, params);
}

const reported = new Set<string>();

/** Warns once per missing key. A warning, not an error: the UI gate fails on console errors. */
export function reportMissing(key: string): void {
    if (reported.has(key)) {
        return;
    }
    reported.add(key);
    console.warn(`i18n: no string for "${key}"`);
}

/** Forgets what was reported (tests only). */
export function resetReported(): void {
    reported.clear();
}

/** The string of `key` in the language of the page. Reactive inside a component. */
export function t(key: string, params?: Params): string {
    return translate(
        (page.props as { translations?: Translations }).translations ?? {},
        key,
        params,
    );
}

/** The current language, for `lang` attributes and `Intl`. */
export function locale(): string {
    return (page.props as { locale?: string }).locale ?? 'en';
}

/**
 * Keeps `<html lang>` on the language of the page. The server writes it on a
 * full load; an Inertia visit (a language change in the settings) only swaps
 * the props, so screen readers and hyphenation would keep the old language.
 */
export function applyDocumentLanguage(
    lang: unknown,
    root: HTMLElement = document.documentElement,
): void {
    if (typeof lang === 'string' && lang !== '' && root.lang !== lang) {
        root.lang = lang;
    }
}
