// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { createRawSnippet, flushSync, mount, tick, unmount } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import SearchDialog from './SearchDialog.svelte';

vi.mock('@inertiajs/svelte', () => ({
    page: {
        props: {
            translations: {
                'search.open': 'Open search',
                'field.clear': 'Clear input',
                'dialog.close': 'Close',
                'search.count.none': 'No results',
                'search.count.one': 'One result',
                'search.count.other': ':count results',
                'search.empty': 'Nothing matches “:query”.',
            },
        },
    },
}));

let app: ReturnType<typeof mount> | undefined;
const $ = (sel: string) => document.querySelector<HTMLElement>(sel);
const item = createRawSnippet((title: () => unknown) => ({ render: () => `<span>${String(title())}</span>` }));

beforeEach(() => vi.useFakeTimers());
afterEach(() => {
    if (app) void unmount(app);
    app = undefined;
    vi.useRealTimers();
});

async function openWith(found: string[]) {
    document.body.innerHTML = '';
    const onpick = vi.fn();
    app = mount(SearchDialog, {
        target: document.body,
        props: { label: 'Search the shares', lookup: async () => found, item, onpick },
    });
    $('button[aria-label="Open search"]')!.click();
    flushSync();
    await vi.advanceTimersByTimeAsync(0);
    await tick();
    const input = $('input[type=search]') as HTMLInputElement;
    input.value = 'mat';
    input.dispatchEvent(new Event('input', { bubbles: true }));
    await vi.advanceTimersByTimeAsync(250);
    flushSync();

    return { input, onpick };
}

const escape = () =>
    document.activeElement!.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true, cancelable: true }));

describe('SearchDialog', () => {
    it('opens with the focus in the search and announces the number of results', async () => {
        const { input } = await openWith(['Matrix', 'Mad Max']);
        expect(document.activeElement).toBe(input);
        expect($('[aria-live]')!.textContent?.trim()).toBe('2 results');
        expect(document.querySelectorAll('.tr-search-hit')).toHaveLength(2);
    });

    it('closes the list with the first Escape and the dialog with the second', async () => {
        await openWith(['Matrix']);
        escape();
        flushSync();
        expect($('.tr-search-results')).toBeNull();
        expect($('[role=dialog]')).not.toBeNull();
        escape();
        flushSync();
        expect($('[role=dialog]')).toBeNull();
        expect(document.activeElement?.getAttribute('aria-label')).toBe('Open search');
    });

    it('keeps the focus in the field on a tap on a result and picks it', async () => {
        const { input, onpick } = await openWith(['Matrix']);
        const hit = $('.tr-search-hit')!;
        const down = new PointerEvent('pointerdown', { bubbles: true, cancelable: true });
        hit.dispatchEvent(down);
        expect(down.defaultPrevented).toBe(true);
        expect(document.activeElement).toBe(input);
        hit.click();
        flushSync();
        expect(onpick).toHaveBeenCalledWith('Matrix');
        expect($('[role=dialog]')).toBeNull();
    });

    it('says when nothing matches', async () => {
        await openWith([]);
        expect($('.tr-search-empty')!.textContent?.trim()).toBe('Nothing matches “mat”.');
        expect($('[aria-live]')!.textContent?.trim()).toBe('Nothing matches “mat”.');
    });

    it('announces a single result in the singular', async () => {
        await openWith(['Matrix']);
        expect($('[aria-live]')!.textContent?.trim()).toBe('One result');
    });
});
