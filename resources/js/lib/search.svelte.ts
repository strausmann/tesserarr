// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The state behind a search field (components/ui/SearchDialog.svelte): waits
 * 250 ms after the last keystroke, then asks; an answer that arrives after a
 * newer question was asked is dropped, and the older request is aborted. So
 * a slow answer to "ma" never replaces the list for "mat".
 */
export const SEARCH_DELAY_MS = 250;

export type Lookup<T> = (query: string, signal: AbortSignal) => Promise<T[]>;

export class Search<T> {
    query = $state('');
    results = $state<T[]>([]);
    loading = $state(false);
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

    /** The field changed: ask again after the delay. */
    type(query: string): void {
        this.query = query;
        clearTimeout(this.#timer);
        // Whatever is still on its way answers an older query: drop it now,
        // not only when the next question is asked after the delay.
        this.#controller?.abort();
        this.#asked++;
        if (query.trim() === '') {
            this.results = [];
            this.loading = false;
            this.open = false;

            return;
        }
        this.open = true;
        this.#timer = setTimeout(() => void this.#ask(query), this.#delay);
    }

    async #ask(query: string): Promise<void> {
        this.#controller?.abort();
        const controller = new AbortController();
        this.#controller = controller;
        const ticket = ++this.#asked;
        this.loading = true;
        try {
            const found = await this.#lookup(query, controller.signal);
            if (ticket === this.#asked) {
                this.results = found;
            }
        } catch {
            if (ticket === this.#asked) {
                this.results = [];
            }
        } finally {
            if (ticket === this.#asked) {
                this.loading = false;
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
        this.loading = false;
    }
}
