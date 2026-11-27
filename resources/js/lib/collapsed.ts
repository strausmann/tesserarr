// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { appRequest, xsrfToken } from './app-api';
import { appFetch } from './updates.svelte';

/**
 * `ui.collapsed`: which sections the user collapsed, kept on the server per
 * user so it holds on every device (App\Http\Controllers\Ui\
 * CollapsedSectionController). The page gets the stored map as the Inertia
 * prop `ui.collapsed`; a section without a stored value uses the default of
 * its page.
 */
export const COLLAPSED_URL = '/api/v1/app/me/ui/collapsed';

/** The stored state of a section, or its default when nothing is stored. */
export function isCollapsed(
    stored: Record<string, boolean> | undefined,
    key: string,
    fallback: boolean,
): boolean {
    const value = stored?.[key];

    return typeof value === 'boolean' ? value : fallback;
}

// Kept here for the callers that import it from this module.
export { xsrfToken };

/**
 * Stores the state of one section. Resolves true when the server took it,
 * false when it refused or could not be reached; the caller keeps showing
 * what the user chose either way, and the next page load shows what is
 * stored.
 */
export async function saveCollapsed(
    key: string,
    collapsed: boolean,
    send: typeof fetch = (input, init) => appFetch(input, init),
): Promise<boolean> {
    try {
        const response = await appRequest(
            COLLAPSED_URL,
            'PUT',
            { section: key, collapsed },
            send,
        );

        return response.ok;
    } catch {
        return false;
    }
}

/** How long a toggle waits for the next one before it is stored. */
export const COLLAPSED_SAVE_DELAY_MS = 300;

/**
 * Sends only the last of quick changes, and one request at a time: a change
 * that comes while a request is on its way is sent after it. So two requests
 * can never arrive in the wrong order and leave the server with a state the
 * user no longer sees. `flush` sends a waiting change at once (the page is
 * left).
 */
export function latestWins<T>(
    send: (value: T) => Promise<unknown>,
    delay: number,
): { set: (value: T) => void; flush: () => void } {
    let wanted: { value: T } | null = null;
    let sending = false;
    let timer: ReturnType<typeof setTimeout> | undefined;

    async function drain(): Promise<void> {
        if (sending) {
            return;
        }
        sending = true;
        while (wanted) {
            const { value } = wanted;
            wanted = null;
            await send(value).catch(() => undefined);
        }
        sending = false;
    }

    return {
        set(value: T) {
            wanted = { value };
            clearTimeout(timer);
            timer = setTimeout(() => void drain(), delay);
        },
        flush() {
            clearTimeout(timer);
            void drain();
        },
    };
}
