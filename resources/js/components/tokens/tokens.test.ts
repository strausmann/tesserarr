// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import type { Component } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { TokenRow } from '@/lib/api-tokens';

const inertia = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown>, url: '/tokens' },
    router: {
        reload: vi.fn(),
        get: vi.fn(),
        post: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
    },
}));

vi.mock('@inertiajs/svelte', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    page: inertia.page,
    router: inertia.router,
}));

const { default: TokenManager } = await import('./TokenManager.svelte');
const { default: AccountTokens } =
    await import('../../pages/account/Tokens.svelte');
const { default: AdminTokens } =
    await import('../../pages/admin/Tokens.svelte');
const { default: FriendHome } = await import('../../pages/friend/Home.svelte');

let app: ReturnType<typeof mount> | undefined;

async function render(component: unknown, props: Record<string, unknown>) {
    // No translations: every label shows its key, which the tests look for.
    inertia.page.props = { translations: {}, errors: {} };
    app = mount(component as Component<Record<string, unknown>>, {
        target: document.body,
        props,
    });
    flushSync();
    await tick();
}

const settle = async () => {
    for (let i = 0; i < 5; i++) {
        await tick();
        await Promise.resolve();
    }
    flushSync();
};

beforeEach(() => {
    document.body.innerHTML = '';
    vi.spyOn(console, 'warn').mockImplementation(() => {});
    inertia.router.reload.mockReset();
});

afterEach(() => {
    if (app) {
        void unmount(app);
    }
    app = undefined;
});

const token = (over: Partial<TokenRow> = {}): TokenRow => ({
    id: 3,
    name: 'Skript bei Sam',
    permissions: ['catalog.browse'],
    created_at: '2026-11-20T10:00:00+00:00',
    last_used_at: null,
    expires_at: '2027-02-24T10:00:00+00:00',
    mine: false,
    owner: { id: 2, username: 'sam', display_name: 'Sam' },
    ...over,
});

const base = (over: Record<string, unknown> = {}) => ({
    tokens: [] as TokenRow[],
    grantable: ['invitations.create', 'catalog.browse', 'users.manage'],
    administrative: ['users.manage'],
    presets: [
        { key: 'arr', permissions: ['catalog.browse'], available: true },
        {
            key: 'invitations',
            permissions: ['invitations.create'],
            available: true,
        },
    ],
    can: { issue: true, revoke_any: true, view_roles: true },
    base_url: 'https://tesserarr.example.org/api/v1/',
    ...over,
});

const byText = (selector: string, text: string) =>
    [...document.querySelectorAll<HTMLElement>(selector)].find((el) =>
        el.textContent?.trim().startsWith(text),
    );
const checkbox = (value: string) =>
    document.querySelector<HTMLInputElement>(
        `input[type="checkbox"][value="${value}"]`,
    )!;
const radio = (value: string) =>
    document.querySelector<HTMLInputElement>(
        `input[type="radio"][value="${value}"]`,
    )!;

describe('the token page', () => {
    it('shows the empty state without tokens and no detail', async () => {
        await render(TokenManager, { scope: 'own', ...base() });

        expect(document.body.textContent).toContain('tokens.empty.title');
        expect(document.querySelector('#token-detail')).toBeNull();
        expect(document.querySelector('h1')?.textContent).toBe(
            'tokens.title_own',
        );
    });

    it('lists every token with its owner and opens the newest in detail', async () => {
        await render(
            AdminTokens,
            base({
                tokens: [
                    token({
                        id: 4,
                        name: 'bot',
                        mine: true,
                        owner: { id: 1, username: 'alex', display_name: '' },
                    }),
                    token(),
                ],
            }),
        );

        const rows = document.querySelectorAll('tbody tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('alex');
        expect(rows[0].textContent).toContain('users.you');
        expect(rows[1].textContent).toContain('Sam');
        expect(document.querySelector('#h-token-detail')?.textContent).toBe(
            'tokens.detail',
        );
        expect(rows[0].getAttribute('aria-current')).toBe('true');

        byText('tbody a', 'Skript bei Sam')!.click();
        flushSync();
        expect(rows[1].getAttribute('aria-current')).toBe('true');
    });

    it('marks administrative permissions and fills the boxes from a preset', async () => {
        await render(AccountTokens, base());

        // The administrative permission is one click further.
        expect(byText('label', 'perm.users.manage')).toBeUndefined();
        byText('a', 'tokens.all_permissions')!.click();
        flushSync();
        expect(byText('label', 'perm.users.manage')?.textContent).toContain(
            'tokens.administrative',
        );
        expect(byText('a', 'tokens.all_permissions')).toBeUndefined();
        expect(
            byText('a', 'tokens.what_permissions_mean')?.getAttribute('href'),
        ).toMatch(/\/roles$/);
        expect(radio('custom').checked).toBe(true);

        radio('arr').click();
        flushSync();
        expect(checkbox('catalog.browse').checked).toBe(true);
        expect(checkbox('users.manage').checked).toBe(false);
        expect(radio('arr').checked).toBe(true);

        checkbox('users.manage').click();
        flushSync();
        expect(radio('custom').checked).toBe(true);
    });

    it('issues a token, shows it once and reloads the list', async () => {
        const issued = token({
            id: 9,
            name: 'Katalog-Export',
            mine: true,
            owner: null,
        });
        const send = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        token: issued,
                        plain_text: '9|tsr_secret',
                    }),
                    { status: 201 },
                ),
        );
        const copy = vi.fn(async () => {});
        await render(TokenManager, { scope: 'own', ...base(), send, copy });

        const name =
            document.querySelector<HTMLInputElement>('input[name="name"]')!;
        name.value = 'Katalog-Export';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        checkbox('catalog.browse').click();
        flushSync();
        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();

        const init = (
            send.mock.calls[0] as unknown as [string, RequestInit]
        )[1];
        expect(JSON.parse(init.body as string)).toEqual({
            name: 'Katalog-Export',
            permissions: ['catalog.browse'],
            expires: '90',
        });
        expect(document.querySelector('#h-issued')?.textContent).toBe(
            'tokens.issued',
        );
        expect(document.body.textContent?.split('9|tsr_secret')).toHaveLength(
            2,
        );
        expect(inertia.router.reload).toHaveBeenCalledWith({
            only: ['tokens'],
        });
        // The form is empty again.
        expect(name.value).toBe('');

        byText('button', 'tokens.copy')!.click();
        await settle();
        expect(copy).toHaveBeenCalledWith('9|tsr_secret');
        expect(byText('button', 'tokens.copied')).toBeDefined();

        document
            .querySelector<HTMLButtonElement>(
                'button[aria-label="tokens.copy_header"]',
            )!
            .click();
        await settle();
        expect(copy).toHaveBeenLastCalledWith(
            'Authorization: Bearer 9|tsr_secret',
        );
    });

    it('shows why a token was refused and offers the password confirmation', async () => {
        const refused = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        message: 'privilege_escalation',
                        refused: ['users.manage'],
                    }),
                    { status: 403 },
                ),
        );
        await render(TokenManager, { scope: 'own', ...base(), send: refused });
        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();
        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'tokens.refused',
        );

        void unmount(app!);
        const confirm = vi.fn(async () => new Response('{}', { status: 423 }));
        await render(TokenManager, { scope: 'own', ...base(), send: confirm });
        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();
        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'tokens.confirm_password',
        );
        expect(
            byText('[role="alert"] a', 'tokens.confirm_again')?.getAttribute(
                'href',
            ),
        ).toBe('/tokens');
    });

    it('sends the chosen expiry and explains every other refusal', async () => {
        const answers = [
            new Response(
                JSON.stringify({
                    errors: {
                        'permissions.0': ['pick one'],
                        expires: ['bad expiry'],
                    },
                }),
                { status: 422 },
            ),
            new Response('{}', { status: 401 }),
            new Response('{}', { status: 500 }),
        ];
        const send = vi.fn(async () => answers.shift()!);
        await render(TokenManager, {
            scope: 'own',
            ...base({
                presets: [
                    {
                        key: 'arr',
                        permissions: ['catalog.browse'],
                        available: false,
                    },
                ],
            }),
            send,
        });
        expect(radio('arr').disabled).toBe(true);

        const select =
            document.querySelector<HTMLSelectElement>('#tk-expires')!;
        select.value = 'never';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        flushSync();
        const submit = async () => {
            document
                .querySelector('form')!
                .dispatchEvent(
                    new Event('submit', { bubbles: true, cancelable: true }),
                );
            await settle();
        };

        await submit();
        const init = (
            send.mock.calls[0] as unknown as [string, RequestInit]
        )[1];
        expect(JSON.parse(init.body as string).expires).toBe('never');
        expect(document.querySelector('#tk-perm-error')?.textContent).toBe(
            'pick one',
        );
        expect(document.body.textContent).toContain('bad expiry');

        await submit();
        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'tokens.signed_out',
        );
        await submit();
        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'tokens.failed',
        );
    });

    it('shows a validation error at its field', async () => {
        const send = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({ errors: { name: ['name too long'] } }),
                    { status: 422 },
                ),
        );
        await render(TokenManager, { scope: 'own', ...base(), send });
        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );
        await settle();

        expect(document.body.textContent).toContain('name too long');
    });

    it('revokes after the confirmation, through the administrative path for another account', async () => {
        const send = vi.fn(async () => new Response(null, { status: 204 }));
        await render(TokenManager, {
            scope: 'all',
            ...base({ tokens: [token()] }),
            send,
        });

        byText('button', 'tokens.revoke')!.click();
        flushSync();
        expect(send).not.toHaveBeenCalled();
        byText('button', 'tokens.revoke_now')!.click();
        await settle();

        expect((send.mock.calls[0] as unknown as [string])[0]).toBe(
            '/api/v1/app/admin/tokens/3',
        );
        expect(inertia.router.reload).toHaveBeenCalledWith({
            only: ['tokens'],
        });
    });

    it('keeps the dialog open with a message when revoking fails', async () => {
        const send = vi.fn(async () => new Response('{}', { status: 500 }));
        await render(TokenManager, {
            scope: 'all',
            ...base({ tokens: [token()] }),
            send,
        });

        byText('button', 'tokens.revoke')!.click();
        flushSync();
        byText('button', 'tokens.revoke_now')!.click();
        await settle();

        expect(document.body.textContent).toContain('tokens.revoke_failed');
        expect(inertia.router.reload).not.toHaveBeenCalled();
    });

    it('offers no revocation of another account’s token without tokens.manage_all, and no form without anything to grant', async () => {
        await render(TokenManager, {
            scope: 'own',
            ...base({
                tokens: [token()],
                can: { issue: false, revoke_any: false, view_roles: false },
            }),
        });

        expect(byText('button', 'tokens.revoke')).toBeUndefined();
        expect(document.querySelector('form')).toBeNull();
    });

    it('holds a 100-character name and many permissions without a wildcard', async () => {
        const long = 'x'.repeat(100);
        await render(TokenManager, {
            scope: 'all',
            ...base({
                tokens: [
                    token({
                        name: long,
                        permissions: [
                            'catalog.browse',
                            'status.view',
                            'audit.view',
                            'users.view',
                        ],
                    }),
                ],
            }),
        });

        expect(document.querySelector('tbody')?.textContent).toContain(long);
        expect(document.querySelector('tbody')?.textContent).toContain(
            'perm.users.view',
        );
    });
});

describe('the friend home', () => {
    it('lists the own tokens by name and links to the token page', async () => {
        await render(FriendHome, {
            tokens: [{ id: 1, name: 'Sonarr', expires_at: null }],
            tokens_total: 6,
        });

        expect(document.body.textContent).toContain('Sonarr');
        expect(document.body.textContent).toContain('friend.tokens.more');
        expect(
            byText('a', 'friend.tokens.manage')?.getAttribute('href'),
        ).toMatch(/\/tokens$/);
    });

    it('shows the empty state without tokens', async () => {
        await render(FriendHome, {});

        expect(document.body.textContent).toContain(
            'friend.tokens.empty.title',
        );
    });
});
