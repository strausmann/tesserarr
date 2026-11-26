// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import type { Component } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown>, url: '/' },
    router: {
        put: vi.fn(),
        post: vi.fn(),
        delete: vi.fn(),
        get: vi.fn(),
        patch: vi.fn(),
    },
}));

vi.mock('@inertiajs/svelte', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    page: inertia.page,
    router: inertia.router,
}));

const { default: Index } = await import('./Index.svelte');
const { default: Activity } = await import('./Activity.svelte');
const { default: UserDetail } = await import('../admin/UserDetail.svelte');
const { default: ConfirmEmail } = await import('../auth/ConfirmEmail.svelte');

let app: ReturnType<typeof mount> | undefined;

async function render(
    component: unknown,
    props: Record<string, unknown>,
    shared: Record<string, unknown> = {},
) {
    // No translations: every label shows its key, which the tests look for.
    inertia.page.props = { translations: {}, errors: {}, ...shared };
    app = mount(component as Component<Record<string, unknown>>, {
        target: document.body,
        props,
    });
    flushSync();
    await tick();
}

beforeEach(() => {
    document.body.innerHTML = '';
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    for (const fn of Object.values(inertia.router)) {
        fn.mockReset();
    }
});

afterEach(() => {
    if (app) {
        void unmount(app);
    }
    app = undefined;
});

const allowed = { allowed: true, reason: null };
const noEmail = {
    confirmed: null,
    pending: null,
    pending_until: null,
    entry: allowed,
};
const entry = (id: number, action = 'email.request') => ({
    id,
    at: '2026-11-27T10:00:00+00:00',
    who: 'alice',
    source: 'ui',
    action,
    subject: 'bob',
    outcome: id === 2 ? 'denied' : 'done',
});
const indexProps = (over: Record<string, unknown> = {}) => ({
    account: { username: 'alice', display_name: '' },
    email: noEmail,
    identities: [],
    suggestion: null,
    security: { passkeys: 0, two_factor: false },
    activity: [],
    ...over,
});

const field = () =>
    document.querySelector<HTMLInputElement>('input[name="email"]')!;
const button = (text: string) =>
    [...document.querySelectorAll<HTMLButtonElement>('button')].find((b) =>
        b.textContent?.trim().startsWith(text),
    );
const type = (value: string) => {
    field().value = value;
    field().dispatchEvent(new Event('input', { bubbles: true }));
    flushSync();
};

describe('My account', () => {
    it('sends a first address to /account/email and empties the field after success', async () => {
        await render(Index, indexProps());

        expect(button('account.email.save')?.disabled).toBe(true);
        type(' alice@example.org ');
        button('account.email.save')!.click();

        // An email input drops the spaces around its value (HTML).
        expect(inertia.router.put).toHaveBeenCalledWith(
            '/account/email',
            { email: 'alice@example.org' },
            expect.objectContaining({ preserveScroll: true }),
        );
        inertia.router.put.mock.calls[0][2].onSuccess();
        inertia.router.put.mock.calls[0][2].onFinish();
        flushSync();
        expect(field().value).toBe('');
    });

    it('offers the provider address as a suggestion and sends it as it is', async () => {
        await render(
            Index,
            indexProps({
                suggestion: 'me@idp.example.net',
                identities: [
                    {
                        id: 1,
                        method: 'oidc',
                        provider_key: 'example',
                        email: 'me@idp.example.net',
                    },
                ],
            }),
        );

        button('account.email.use_suggestion')!.click();
        expect(inertia.router.put).toHaveBeenCalledWith(
            '/account/email',
            { email: 'me@idp.example.net' },
            expect.anything(),
        );
        expect(document.body.textContent).toContain('oidc · example');
    });

    it('locks the field and says why when addresses are switched off', async () => {
        await render(
            Index,
            indexProps({
                email: {
                    ...noEmail,
                    entry: { allowed: false, reason: 'no_transport' },
                },
            }),
        );

        expect(field().disabled).toBe(true);
        expect(document.getElementById('email-locked')?.textContent).toBe(
            'account.email.disabled.no_transport',
        );
        expect(button('account.email.use_suggestion')).toBeUndefined();
    });

    it('locks a confirmed address until the change with a second factor exists', async () => {
        await render(
            Index,
            indexProps({
                email: { ...noEmail, confirmed: 'alice@example.org' },
            }),
        );

        expect(field().disabled).toBe(true);
        expect(document.getElementById('email-locked')?.textContent).toBe(
            'account.email.disabled.confirmed_exists',
        );
        expect(document.body.textContent).toContain('alice@example.org');
    });

    it('shows the waiting address and sends the link again', async () => {
        await render(
            Index,
            indexProps({
                email: {
                    ...noEmail,
                    pending: 'next@example.org',
                    pending_until: '2026-11-28T10:00:00+00:00',
                },
            }),
        );

        expect(document.body.textContent).toContain('next@example.org');
        button('account.email.resend')!.click();
        expect(inertia.router.post).toHaveBeenCalledWith(
            '/account/email/resend',
            {},
            expect.anything(),
        );
        expect(button('account.email.release')).toBeUndefined();
    });

    it('shows the field error and the recent activity with a link to all of it', async () => {
        await render(
            Index,
            indexProps({
                activity: [entry(1), entry(2, 'email.claim_rejected')],
            }),
            {
                errors: { email: 'taken' },
            },
        );

        expect(document.body.textContent).toContain('taken');
        expect(document.querySelectorAll('.tr-activity-row')).toHaveLength(2);
        expect(
            document.querySelector('a[href$="/account/activity"]'),
        ).not.toBeNull();
        expect(document.querySelector('.tr-tag--bad')?.textContent).toBe(
            'audit.outcome.denied',
        );
    });

    it('keeps worst-case data inside the page: a 254-character address, unicode, many identities', async () => {
        const long = `${'a'.repeat(64)}@${'b'.repeat(63)}.${'c'.repeat(63)}.${'d'.repeat(57)}.test`;
        await render(
            Index,
            indexProps({
                account: { username: 'x', display_name: 'Jérôme 山田 🙂' },
                email: { ...noEmail, confirmed: long },
                identities: Array.from({ length: 12 }, (_, i) => ({
                    id: i,
                    method: 'oidc',
                    provider_key: `p${i}`,
                    email: `j\u00e9r\u00f4me${i}@b\u00fccher.test`,
                })),
                security: { passkeys: 9999, two_factor: true },
            }),
            { translations: { 'account.lead': ':name, :username' } },
        );

        expect(document.querySelector('.tr-email-address')?.textContent).toBe(
            long,
        );
        expect(document.querySelectorAll('#h-identities ~ ul li')).toHaveLength(
            12,
        );
        expect(document.body.textContent).toContain('Jérôme 山田 🙂');
    });
});

describe('My activity', () => {
    it('lists the entries, or the empty state', async () => {
        await render(Activity, { entries: [entry(1)] });
        expect(document.querySelectorAll('.tr-activity-row')).toHaveLength(1);
        void unmount(app!);
        app = undefined;
        document.body.innerHTML = '';

        await render(Activity, { entries: [] });
        expect(document.body.textContent).toContain(
            'account.activity.empty.title',
        );
    });
});

describe('User detail', () => {
    const props = (over: Record<string, unknown> = {}) => ({
        account: {
            id: 7,
            username: 'bob',
            display_name: 'Bob',
            role: 'user',
            locked_at: null,
            self: false,
        },
        email: {
            ...noEmail,
            confirmed: 'old@example.org',
            pending: 'new@example.org',
            pending_until: '2026-11-28T10:00:00+00:00',
        },
        identities: [
            {
                id: 1,
                method: 'plex',
                provider_key: 'plex',
                provider_email: null,
            },
        ],
        entries: [],
        ...over,
    });

    it('sends the address for the account and frees its reservation', async () => {
        await render(UserDetail, props(), {
            permissions: ['users.view', 'users.manage'],
        });

        type('bob@example.org');
        button('users.detail.email_save')!.click();
        expect(inertia.router.put).toHaveBeenCalledWith(
            '/users/7/email',
            { email: 'bob@example.org' },
            expect.anything(),
        );

        button('account.email.release')!.click();
        expect(inertia.router.delete).toHaveBeenCalledWith(
            '/users/7/email/pending',
            expect.anything(),
        );
        expect(document.body.textContent).toContain(
            'users.detail.entries_empty',
        );
        expect(document.body.textContent).toContain('roles.name.user');
    });

    it('is read-only without users.manage', async () => {
        await render(
            UserDetail,
            props({
                account: {
                    id: 7,
                    username: 'bob',
                    display_name: '',
                    role: null,
                    locked_at: '2026-01-01',
                    self: false,
                },
            }),
            {
                permissions: ['users.view'],
            },
        );

        expect(field().disabled).toBe(true);
        expect(button('account.email.release')).toBeUndefined();
        expect(document.querySelector('h1')?.textContent).toBe('bob');
        expect(document.body.textContent).toContain('users.locked');
    });
});

describe('Confirm email', () => {
    it('reads the token from the fragment and uses it only when the button is pressed', async () => {
        window.location.hash = '#abc%2Fdef';
        await render(ConfirmEmail, {});
        expect(inertia.router.post).not.toHaveBeenCalled();

        button('account.confirm.button')!.click();
        expect(inertia.router.post).toHaveBeenCalledWith(
            '/email/confirm',
            { token: 'abc/def' },
            expect.anything(),
        );
        inertia.router.post.mock.calls[0][2].onFinish();
    });

    it('says the link is not valid when the fragment is missing', async () => {
        window.location.hash = '';
        await render(ConfirmEmail, {});
        expect(document.querySelector('[role="alert"]')?.textContent).toBe(
            'account.email.link_invalid',
        );
        expect(button('account.confirm.button')?.disabled).toBe(true);
    });

    it('shows why a link is refused', async () => {
        window.location.hash = '#t';
        await render(ConfirmEmail, {}, { errors: { token: 'not valid' } });
        expect(document.querySelector('[role="alert"]')?.textContent).toBe(
            'not valid',
        );
    });
});
