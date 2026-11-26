// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { Search, SEARCH_DELAY_MS } from './search.svelte';

beforeEach(() => vi.useFakeTimers());
afterEach(() => vi.useRealTimers());

/** A lookup whose answers a test releases one by one. */
function controlled() {
    const pending: Array<{
        query: string;
        signal: AbortSignal;
        resolve: (v: string[]) => void;
        reject: (e: unknown) => void;
    }> = [];
    const lookup = vi.fn(
        (query: string, signal: AbortSignal) =>
            new Promise<string[]>((resolve, reject) =>
                pending.push({ query, signal, resolve, reject }),
            ),
    );

    return { lookup, pending };
}

describe('Search', () => {
    it('waits 250 ms after the last keystroke before it asks', async () => {
        const { lookup } = controlled();
        const search = new Search(lookup);
        search.type('m');
        search.type('ma');
        await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS - 1);
        expect(lookup).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(lookup).toHaveBeenCalledOnce();
        expect(lookup.mock.calls[0][0]).toBe('ma');
        expect(SEARCH_DELAY_MS).toBe(250);
    });

    it('drops an answer that arrives after a newer question and aborts the old request', async () => {
        const { lookup, pending } = controlled();
        const search = new Search(lookup);
        search.type('ma');
        await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS);
        search.type('mat');
        await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS);

        expect(pending[0].signal.aborted).toBe(true);
        pending[1].resolve(['Matrix']);
        await vi.runAllTimersAsync();
        pending[0].resolve(['Mad Max', 'Matrix']);
        await vi.runAllTimersAsync();

        expect(search.results).toEqual(['Matrix']);
        expect(search.loading).toBe(false);
    });

    it('shows no results after a failed lookup', async () => {
        const { lookup, pending } = controlled();
        const search = new Search(lookup);
        search.type('x');
        await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS);
        expect(search.loading).toBe(true);
        pending[0].reject(new TypeError('Failed to fetch'));
        await vi.runAllTimersAsync();
        expect(search.results).toEqual([]);
        expect(search.loading).toBe(false);
    });

    it('closes the list on Escape first and leaves the second Escape to the dialog', () => {
        const search = new Search(controlled().lookup);
        search.type('ma');
        expect(search.open).toBe(true);
        expect(search.escape()).toBe(true);
        expect(search.open).toBe(false);
        expect(search.escape()).toBe(false);
    });

    it('clears everything when the field is emptied and ignores a late answer', async () => {
        const { lookup, pending } = controlled();
        const search = new Search(lookup);
        search.type('ma');
        await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS);
        search.type('');
        pending[0].resolve(['late']);
        await vi.runAllTimersAsync();
        expect(search.results).toEqual([]);
        expect(search.open).toBe(false);
    });

    it('stops a pending question', async () => {
        const { lookup } = controlled();
        const search = new Search(lookup);
        search.type('ma');
        search.stop();
        await vi.advanceTimersByTimeAsync(SEARCH_DELAY_MS);
        expect(lookup).not.toHaveBeenCalled();
    });
});
