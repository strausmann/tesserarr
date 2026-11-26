// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    COLLAPSED_URL,
    isCollapsed,
    latestWins,
    saveCollapsed,
    xsrfToken,
} from './collapsed';

afterEach(() => {
    document.cookie = 'XSRF-TOKEN=; expires=Thu, 01 Jan 1970 00:00:00 GMT';
});

describe('isCollapsed', () => {
    it('takes the stored value over the default of the page', () => {
        expect(isCollapsed({ a: true }, 'a', false)).toBe(true);
        expect(isCollapsed({ a: false }, 'a', true)).toBe(false);
    });

    it('uses the default when nothing is stored', () => {
        expect(isCollapsed({}, 'a', true)).toBe(true);
        expect(isCollapsed(undefined, 'a', false)).toBe(false);
    });
});

describe('xsrfToken', () => {
    it('reads and decodes the cookie', () => {
        expect(xsrfToken('a=1; XSRF-TOKEN=ab%3Dcd; b=2')).toBe('ab=cd');
        expect(xsrfToken('a=1')).toBeNull();
    });
});

describe('saveCollapsed', () => {
    it('sends the section and its state with the CSRF token', async () => {
        document.cookie = 'XSRF-TOKEN=tok%3D';
        const send = vi.fn(async () => new Response(null, { status: 204 }));

        await expect(
            saveCollapsed('dashboard.attention', true, send),
        ).resolves.toBe(true);

        expect(send).toHaveBeenCalledWith(
            COLLAPSED_URL,
            expect.objectContaining({ method: 'PUT' }),
        );
        const init = (
            send.mock.calls[0] as unknown as [string, RequestInit]
        )[1];
        expect(JSON.parse(init.body as string)).toEqual({
            section: 'dashboard.attention',
            collapsed: true,
        });
        expect((init.headers as Record<string, string>)['X-XSRF-TOKEN']).toBe(
            'tok=',
        );
    });

    it('sends no token header without the cookie', async () => {
        const send = vi.fn(async () => new Response(null, { status: 204 }));
        await saveCollapsed('a', false, send);
        const init = (
            send.mock.calls[0] as unknown as [string, RequestInit]
        )[1];
        expect(init.headers).not.toHaveProperty('X-XSRF-TOKEN');
    });

    it('reports a refused value (422) and a server error (500)', async () => {
        for (const status of [422, 500]) {
            const send = vi.fn(async () => new Response('{}', { status }));
            await expect(saveCollapsed('nope', true, send)).resolves.toBe(
                false,
            );
        }
    });

    it('reports a network error instead of throwing', async () => {
        const send = vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        });
        await expect(saveCollapsed('a', true, send)).resolves.toBe(false);
    });
});

describe('latestWins', () => {
    afterEach(() => vi.useRealTimers());

    it('sends only the last of quick changes', async () => {
        vi.useFakeTimers();
        const send = vi.fn(async () => true);
        const store = latestWins(send, 300);
        store.set(true);
        store.set(false);
        store.set(true);
        await vi.advanceTimersByTimeAsync(299);
        expect(send).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(send).toHaveBeenCalledOnce();
        expect(send).toHaveBeenCalledWith(true);
    });

    it('waits for the request on its way before it sends the next, so the last state arrives last', async () => {
        vi.useFakeTimers();
        const order: boolean[] = [];
        let release: () => void = () => {};
        const send = vi.fn((value: boolean) => {
            order.push(value);
            return value
                ? new Promise<void>((r) => (release = r))
                : Promise.resolve();
        });
        const store = latestWins(send, 300);
        store.set(true);
        await vi.advanceTimersByTimeAsync(300);
        store.set(false);
        await vi.advanceTimersByTimeAsync(300);
        expect(order).toEqual([true]);
        release();
        await vi.runAllTimersAsync();
        expect(order).toEqual([true, false]);
    });

    it('sends a waiting change at once on flush and survives a failed request', async () => {
        vi.useFakeTimers();
        const send = vi.fn(async () => {
            throw new TypeError('Failed to fetch');
        });
        const store = latestWins(send, 300);
        store.set(false);
        store.flush();
        await vi.runAllTimersAsync();
        expect(send).toHaveBeenCalledWith(false);
        store.set(true);
        await vi.advanceTimersByTimeAsync(300);
        expect(send).toHaveBeenCalledTimes(2);
    });
});
