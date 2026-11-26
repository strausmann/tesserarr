// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The state behind a search field (components/ui/SearchDialog.svelte): waits
 * 250 ms after the last keystroke, then asks; an answer that arrives after a
 * newer question was asked is dropped, and the older request is aborted. So
 * a slow answer to "ma" never replaces the list for "mat".
 *
 * `status` says what the list may claim: `waiting` from the keystroke until
 * the answer (the old results are gone, nothing is announced), `done` once
 * there is an answer (only now may "no results" be said), `error` when the
 * lookup failed (not the same as no results).
 */
export const SEARCH_DELAY_MS = 250;

export type Lookup<T> = (query: string, signal: AbortSignal) => Promise<T[]>;
export type SearchStatus = 'idle' | 'waiting' | 'done' | 'error';

export class Search<T> {
    query = $state('');
    results = $state<T[]>([]);
    status = $state<SearchStatus>('idle');
    /** The list is open while there is a query; Escape closes it first (two steps). */
    open = $state(false);

    #timer: ReturnType<typeof setTimeout> | undefined;
    #asked = 0;
    #controller: AbortController | undefined;
    readonly #lookup: Lookup<T>;
    readonly #delay: number;

    constructor(lookup: Lookup<T>, delay = SEARCH_DELAY_MS) {
        this.#lookup = lookup;
        this.#delay = delay;
    }

    /** The field changed: drop what is on its way, ask again after the delay. */
    type(query: string): void {
        this.query = query;
        clearTimeout(this.#timer);
        this.#controller?.abort();
        this.#asked++;
        this.results = [];
        if (query.trim() === '') {
            this.status = 'idle';
            this.open = false;

            return;
        }
        this.status = 'waiting';
        this.open = true;
        this.#timer = setTimeout(() => void this.#ask(query), this.#delay);
    }

    async #ask(query: string): Promise<void> {
        const controller = new AbortController();
        this.#controller = controller;
        const ticket = ++this.#asked;
        try {
            const found = await this.#lookup(query, controller.signal);
            if (ticket === this.#asked) {
                this.results = found;
                this.status = 'done';
            }
        } catch {
            if (ticket === this.#asked) {
                this.results = [];
                this.status = 'error';
            }
        }
    }

    /**
     * Escape: the first one closes the list and returns true (handled), the
     * second finds it closed and returns false, so the dialog closes.
     */
    escape(): boolean {
        if (this.open) {
            this.open = false;

            return true;
        }

        return false;
    }

    /** Stops whatever is pending (the dialog closed). */
    stop(): void {
        clearTimeout(this.#timer);
        this.#controller?.abort();
        this.#asked++;
        if (this.status === 'waiting') {
            this.status = 'idle';
        }
    }
}
