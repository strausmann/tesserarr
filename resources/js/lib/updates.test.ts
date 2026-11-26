// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, unmount } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import UpdateNoticeView from '@/components/UpdateNotice.svelte';
import {
    appFetch,
    BUILD_VERSION,
    checkVersion,
    DISMISSED_KEY,
    readVersion,
    startVersionChecks,
    UpdateNotice,
    VERSION_CHECK_INTERVAL_MS,
    VERSION_URL,
    watchInertia,
} from './updates.svelte';

vi.mock('@inertiajs/svelte', () => ({
    page: {
        props: {
            translations: {
                'update.available': 'New version available',
                'update.version': 'Version :version',
                'update.ready': 'Version :version is ready',
                'update.ready_hint': 'Reload to use it.',
                'update.reload': 'Reload',
                'update.later': 'Later',
                'update.region': 'New version',
            },
        },
    },
}));

// Counterparts of Labelyard frontend/src/app/versionGuard.test.tsx.

const BUILT_FOR = '0.1.0';
const SERVER = '0.1.1';

let apps: ReturnType<typeof mount>[] = [];
let reload: ReturnType<typeof vi.fn<() => void>>;

function fresh(storage: Storage | null = window.localStorage): UpdateNotice {
    return new UpdateNotice({
        buildVersion: BUILT_FOR,
        reload,
        storage: () => storage,
    });
}

function show(notice: UpdateNotice): void {
    apps.push(
        mount(UpdateNoticeView, {
            target: document.body,
            props: { variant: 'box', notice },
        }),
        mount(UpdateNoticeView, {
            target: document.body,
            props: { variant: 'toast', notice },
        }),
    );
    flushSync();
}

const toast = () =>
    document.querySelector<HTMLElement>('section[aria-label="New version"]');
const box = () => document.querySelector<HTMLElement>('.tr-update-box');
const button = (name: string, root: ParentNode = document) =>
    [...root.querySelectorAll<HTMLButtonElement>('button')].find(
        (b) => b.textContent?.trim() === name,
    );

function headers(version: string | null): Headers {
    const bag = new Headers({ 'Content-Type': 'application/json' });
    if (version !== null) {
        bag.set('X-Tesserarr-Version', version);
    }

    return bag;
}

beforeEach(() => {
    reload = vi.fn<() => void>();
    window.localStorage.clear();
    document.body.innerHTML = '';
});

afterEach(() => {
    apps.forEach((app) => void unmount(app));
    apps = [];
    vi.useRealTimers();
});

describe('the update notice', () => {
    it('shows the banner and never reloads by itself', () => {
        const notice = fresh();
        show(notice);
        expect(toast()).toBeNull();

        notice.note(SERVER);
        notice.note(SERVER);
        flushSync();

        expect(toast()?.textContent).toContain('New version available');
        expect(toast()?.textContent).toContain(`Version ${SERVER}`);
        expect(box()?.textContent).toContain(`Version ${SERVER} is ready`);
        expect(toast()?.querySelector('[role="status"]')).not.toBeNull();
        expect(reload).not.toHaveBeenCalled();

        button('Reload', toast()!)!.click();
        expect(reload).toHaveBeenCalledOnce();
    });

    it('does nothing while the versions are equal or the server names none', () => {
        const notice = fresh();
        show(notice);

        notice.note(BUILT_FOR);
        notice.note(null);
        notice.note(undefined);
        notice.note('');
        notice.note('   ');
        flushSync();

        expect(notice.state).toEqual({
            waiting: false,
            version: null,
            toastOpen: false,
        });
        expect(toast()).toBeNull();
        expect(box()).toBeNull();
        expect(reload).not.toHaveBeenCalled();
    });

    it('takes the banner down when the server reports the bundle version again', () => {
        const notice = fresh();
        show(notice);
        notice.note(SERVER);
        flushSync();
        expect(box()).not.toBeNull();

        notice.note(BUILT_FOR);
        flushSync();

        expect(notice.state.waiting).toBe(false);
        expect(box()).toBeNull();
        expect(toast()).toBeNull();
    });

    it('reads the version from error responses too', async () => {
        const notice = fresh();
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response('{}', {
                        status: 500,
                        headers: headers(SERVER),
                    }),
            ),
        );

        const response = await appFetch('/api/v1/app/me', undefined, notice);

        expect(response.status).toBe(500);
        expect(notice.state).toEqual({
            waiting: true,
            version: SERVER,
            toastOpen: true,
        });

        // An Inertia background reload of an outdated tab: a 409, which
        // Inertia hands to its error handlers.
        notice.note(BUILT_FOR);
        const handlers: {
            response?: (r: { headers: Record<string, string> }) => unknown;
            error?: (e: unknown) => void;
        } = {};
        const stop = watchInertia(
            {
                onResponse: (h) => {
                    handlers.response = h as never;

                    return () => undefined;
                },
                onError: (h) => {
                    handlers.error = h;

                    return () => undefined;
                },
            },
            notice,
        );
        handlers.error!({
            response: {
                status: 409,
                headers: { 'x-tesserarr-version': '0.2.0' },
            },
        });
        expect(notice.state.version).toBe('0.2.0');
        handlers.error!(new Error('network'));
        handlers.response!({ headers: { 'x-tesserarr-version': BUILT_FOR } });
        expect(notice.state.waiting).toBe(false);
        stop();
    });

    it('keeps Later for that version across tabs and opens again for a newer one', () => {
        const notice = fresh();
        show(notice);
        notice.note(SERVER);
        flushSync();

        button('Later', toast()!)!.click();
        flushSync();

        expect(toast()).toBeNull();
        expect(box()).not.toBeNull(); // the box stays until the reload
        expect(window.localStorage.getItem(DISMISSED_KEY)).toBe(SERVER);

        // A second tab of the same browser starts with the toast closed.
        const other = fresh();
        other.note(SERVER);
        expect(other.state.toastOpen).toBe(false);

        // A newer version opens it again.
        notice.note('0.1.2');
        flushSync();
        expect(toast()).not.toBeNull();
    });

    it('follows Later of another tab while it is open', () => {
        const notice = fresh();
        notice.note(SERVER);
        const stop = startVersionChecks(notice, 60_000);

        window.localStorage.setItem(DISMISSED_KEY, SERVER);
        window.dispatchEvent(
            new StorageEvent('storage', { key: DISMISSED_KEY }),
        );

        expect(notice.state.toastOpen).toBe(false);
        stop();
    });

    it('keeps Later for this tab when the storage is blocked', () => {
        const blocked = {
            getItem: () => {
                throw new Error('blocked');
            },
            setItem: () => {
                throw new Error('blocked');
            },
        } as unknown as Storage;
        const notice = fresh(blocked);
        notice.note(SERVER);

        notice.dismiss();

        expect(notice.state.toastOpen).toBe(false);
        expect(fresh(null).state.toastOpen).toBe(false);
    });

    it('leaves a dialog with typed input alone', () => {
        document.body.innerHTML =
            '<div role="dialog" aria-label="Invite"><input aria-label="Name" value=""></div>';
        const input = document.querySelector<HTMLInputElement>('input')!;
        input.focus();
        input.value = 'Alice';
        const notice = fresh();
        show(notice);

        notice.note(SERVER);
        flushSync();

        expect(reload).not.toHaveBeenCalled();
        expect(input.value).toBe('Alice');
        expect(document.activeElement).toBe(input);
    });

    it('shows a version from outside as text, never as markup', () => {
        const notice = fresh();
        show(notice);

        notice.note('<img src=x onerror="window.__owned=1">');
        flushSync();

        expect(document.querySelector('img')).toBeNull();
        expect(toast()?.textContent).toContain('<img src=x');
    });
});

describe('asking the server', () => {
    it('asks the session API every five minutes and when the tab is visible again', async () => {
        vi.useFakeTimers();
        const notice = fresh();
        const send = vi.fn(
            async (_input: RequestInfo | URL, _init?: RequestInit) =>
                new Response('{"version":"0.1.1"}', {
                    headers: headers(SERVER),
                }),
        );
        vi.stubGlobal('fetch', send);
        const stop = startVersionChecks(notice);

        await vi.advanceTimersByTimeAsync(VERSION_CHECK_INTERVAL_MS - 1);
        expect(send).not.toHaveBeenCalled();
        await vi.advanceTimersByTimeAsync(1);
        expect(send).toHaveBeenCalledOnce();
        expect(send.mock.calls[0][0]).toBe(VERSION_URL);
        expect(notice.state.version).toBe(SERVER);

        document.dispatchEvent(new Event('visibilitychange'));
        window.dispatchEvent(new Event('online'));
        await vi.advanceTimersByTimeAsync(0);
        expect(send).toHaveBeenCalledTimes(3);

        stop();
        await vi.advanceTimersByTimeAsync(VERSION_CHECK_INTERVAL_MS);
        expect(send).toHaveBeenCalledTimes(3);
        expect(VERSION_CHECK_INTERVAL_MS).toBe(5 * 60 * 1000);
    });

    it('stays quiet when the server cannot be reached', async () => {
        const notice = fresh();
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => {
                throw new TypeError('Failed to fetch');
            }),
        );

        await expect(checkVersion(notice)).resolves.toBeUndefined();
        expect(notice.state.waiting).toBe(false);
    });

    it('reads the header from fetch and from Inertia', () => {
        expect(readVersion(headers('1.0.0'))).toBe('1.0.0');
        expect(readVersion(headers(null))).toBeNull();
        expect(readVersion({ 'x-tesserarr-version': '1.0.0' })).toBe('1.0.0');
        expect(readVersion({})).toBeNull();
        expect(readVersion(undefined)).toBeNull();
    });

    it('falls back to 0.0.0-dev when the bundle was built without a version', () => {
        expect(BUILD_VERSION).toBe('0.0.0-dev');
    });
});
