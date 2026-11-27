// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import type { Component } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import type { InvitationRow } from '@/lib/invitations';

const inertia = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown>, url: '/users' },
    router: {
        post: vi.fn(),
        get: vi.fn(),
        put: vi.fn(),
        delete: vi.fn(),
        reload: vi.fn(),
    },
}));

vi.mock('@inertiajs/svelte', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    page: inertia.page,
    router: inertia.router,
}));

const { default: Invitations } = await import('./Invitations.svelte');
const { default: Redeem } = await import('../../pages/auth/Redeem.svelte');

// 43 characters of base64url, as a real token, but plainly made up.
const TOKEN = 'EXAMPLE_invitation_token_' + '0'.repeat(18);
const NOW = new Date('2026-11-27T12:00:00Z');

let app: ReturnType<typeof mount> | undefined;

async function render(component: unknown, props: Record<string, unknown>) {
    // No translations: every label shows its key.
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

function row(id: number, extra: Partial<InvitationRow> = {}): InvitationRow {
    return {
        id,
        kind: 'access',
        note: `Note ${id}`,
        expires_at: '2026-12-04T12:00:00Z',
        created_at: '2026-11-27T12:00:00Z',
        created_by: 'Alex',
        via: 'ui',
        for_user: null,
        ...extra,
    };
}

function button(text: string): HTMLButtonElement {
    const found = [...document.querySelectorAll('button')].find(
        (b) => b.textContent?.trim() === text,
    );
    if (!found) {
        throw new Error(`no button ${text}`);
    }

    return found;
}

beforeEach(() => {
    document.body.innerHTML = '';
    inertia.page.props = { translations: {}, errors: {} };
    inertia.router.post.mockReset();
    vi.spyOn(console, 'warn').mockImplementation(() => {});
});

afterEach(() => {
    if (app) {
        void unmount(app);
        app = undefined;
    }
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
});

describe('Invitations', () => {
    it('shows the empty state with the invite action for who may create', async () => {
        await render(Invitations, {
            invitations: [],
            canCreate: true,
            canRevoke: true,
            now: () => NOW,
        });

        expect(document.body.textContent).toContain('invitations.empty.title');
        expect(button('invitations.invite')).toBeTruthy();
    });

    it('shows no invite action without invitations.create', async () => {
        await render(Invitations, {
            invitations: [],
            canCreate: false,
            canRevoke: true,
            now: () => NOW,
        });

        expect(
            [...document.querySelectorAll('button')].some((b) =>
                b.textContent?.includes('invitations.invite'),
            ),
        ).toBe(false);
    });

    it('lists open invitations with the days left, a recovery one by its account', async () => {
        await render(Invitations, {
            invitations: [
                row(1),
                row(2, {
                    kind: 'recovery',
                    for_user: 'sam',
                    note: null,
                    via: 'cli',
                    created_by: null,
                }),
            ],
            canCreate: true,
            canRevoke: true,
            now: () => NOW,
        });

        const rows = document.querySelectorAll('tbody tr');
        expect(rows).toHaveLength(2);
        expect(rows[0].textContent).toContain('Note 1');
        expect(rows[0].textContent).toContain('invitations.left');
        expect(rows[1].textContent).toContain('invitations.recovery_for');
        expect(rows[1].textContent).toContain('invitations.via.cli');
    });

    it('creates an invitation, shows its link once and forgets it on close', async () => {
        const fetchMock = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        invitation: row(5, { note: 'Noa' }),
                        url: `https://tesserarr.example.org/invite#${TOKEN}`,
                    }),
                    { status: 201 },
                ),
        );
        vi.stubGlobal('fetch', fetchMock);
        const copy = vi.fn(async () => {});
        await render(Invitations, {
            invitations: [],
            canCreate: true,
            canRevoke: true,
            copy,
            now: () => NOW,
        });

        button('invitations.invite').click();
        await settle();
        const note = document.querySelector<HTMLInputElement>(
            'form#invitation-form input',
        );
        note!.value = 'Noa';
        note!.dispatchEvent(new Event('input', { bubbles: true }));
        document
            .querySelector<HTMLFormElement>('form#invitation-form')!
            .requestSubmit();
        await settle();
        await settle();

        expect(
            JSON.parse(
                ((fetchMock.mock.calls[0] as unknown[])[1] as RequestInit)
                    .body as string,
            ),
        ).toEqual({ days: 7, note: 'Noa' });
        expect(document.body.textContent).toContain(TOKEN);
        expect(document.querySelectorAll('tbody tr')).toHaveLength(1);

        button('invitations.copy').click();
        await settle();
        expect(copy).toHaveBeenCalledWith(
            `https://tesserarr.example.org/invite#${TOKEN}`,
        );
        expect(document.body.textContent).toContain('invitations.copied');

        button('invitations.done.close').click();
        await settle();
        expect(document.body.textContent).not.toContain(TOKEN);
    });

    it('shows the refusal of the server and keeps the dialog open', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(
                async () =>
                    new Response(
                        JSON.stringify({ message: 'privilege_escalation' }),
                        { status: 403 },
                    ),
            ),
        );
        await render(Invitations, {
            invitations: [],
            canCreate: true,
            canRevoke: true,
            now: () => NOW,
        });

        button('invitations.invite').click();
        await settle();
        document
            .querySelector<HTMLFormElement>('form#invitation-form')!
            .requestSubmit();
        await settle();
        await settle();

        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'invitations.error.privilege_escalation',
        );
        expect(document.querySelector('form#invitation-form')).not.toBeNull();
    });

    it('revokes after asking and removes the row; a vanished one goes as well', async () => {
        const fetchMock = vi.fn(
            async () => new Response(null, { status: 204 }),
        );
        vi.stubGlobal('fetch', fetchMock);
        await render(Invitations, {
            invitations: [row(1), row(2)],
            canCreate: true,
            canRevoke: true,
            now: () => NOW,
        });

        document.querySelector<HTMLButtonElement>('tbody tr button')!.click();
        await settle();
        button('invitations.detail.revoke').click();
        await settle();
        expect(fetchMock).not.toHaveBeenCalled();
        button('invitations.detail.revoke_now').click();
        await settle();
        await settle();

        expect((fetchMock.mock.calls[0] as unknown[])[0]).toBe(
            '/api/v1/app/invitations/1',
        );
        expect(document.querySelectorAll('tbody tr')).toHaveLength(1);
    });

    it('offers no revoke without invitations.revoke', async () => {
        await render(Invitations, {
            invitations: [row(1)],
            canCreate: true,
            canRevoke: false,
            now: () => NOW,
        });

        document.querySelector<HTMLButtonElement>('tbody tr button')!.click();
        await settle();

        expect(
            [...document.querySelectorAll('button')].some((b) =>
                b.textContent?.includes('invitations.detail.revoke'),
            ),
        ).toBe(false);
    });
});

describe('Redeem', () => {
    it('takes the token out of the address bar and looks the invitation up in the body', async () => {
        window.history.replaceState(null, '', `/invite#${TOKEN}`);
        await render(Redeem, { invitation: null, methods: [] });
        await settle();

        expect(window.location.hash).toBe('');
        expect(inertia.router.post).toHaveBeenCalledWith(
            '/invite',
            { token: TOKEN },
            expect.anything(),
        );
    });

    it('asks for the link when the address carries none, and posts a pasted link as its token', async () => {
        window.history.replaceState(null, '', '/invite');
        await render(Redeem, { invitation: null, methods: [] });
        expect(inertia.router.post).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain(
            'invitations.redeem.paste_lead',
        );

        const input = document.querySelector<HTMLInputElement>('form input')!;
        input.value = `https://tesserarr.example.org/invite#${TOKEN}`;
        input.dispatchEvent(new Event('input', { bubbles: true }));
        flushSync();
        document.querySelector<HTMLFormElement>('form')!.requestSubmit();
        await settle();

        expect(inertia.router.post).toHaveBeenCalledWith(
            '/invite',
            { token: TOKEN },
            expect.anything(),
        );
    });

    it('shows the one message for an invitation that cannot be used', async () => {
        window.history.replaceState(null, '', '/invite');
        inertia.page.props = {
            translations: {},
            errors: { token: 'cannot be used' },
        };
        await render(Redeem, { invitation: null, methods: [] });

        expect(document.querySelector('[role="alert"]')?.textContent).toBe(
            'cannot be used',
        );
    });

    it('welcomes, then shows the method step with the note that no method is on yet', async () => {
        window.history.replaceState(null, '', '/invite');
        await render(Redeem, {
            invitation: {
                kind: 'access',
                inviter: 'Alex',
                expires_at: '2026-12-04T12:00:00Z',
                account: null,
            },
            methods: [],
        });

        expect(
            document
                .querySelector('[role="progressbar"]')
                ?.getAttribute('aria-valuenow'),
        ).toBe('1');
        expect(document.querySelector('h2')?.textContent).toContain(
            'invitations.redeem.welcome',
        );
        button('invitations.redeem.start').click();
        await settle();

        expect(
            document
                .querySelector('[role="progressbar"]')
                ?.getAttribute('aria-valuenow'),
        ).toBe('2');
        expect(
            document.querySelector('[role="status"]')?.textContent,
        ).toContain('invitations.redeem.no_method_by');
        expect(inertia.router.post).not.toHaveBeenCalled();
    });

    it('names the account of a recovery invitation', async () => {
        window.history.replaceState(null, '', '/invite');
        await render(Redeem, {
            invitation: {
                kind: 'recovery',
                inviter: null,
                expires_at: '2026-12-04T12:00:00Z',
                account: 'sam',
            },
            methods: [
                {
                    key: 'oidc',
                    label: 'Home sign-in',
                    hint: 'Through the operator',
                },
            ],
        });

        expect(document.querySelector('h2')?.textContent).toContain(
            'invitations.redeem.recovery_title',
        );
        expect(document.body.textContent).toContain('sam');
        button('invitations.redeem.start').click();
        await settle();
        const radio =
            document.querySelector<HTMLButtonElement>('[role="radio"]')!;
        radio.click();
        await settle();
        expect(radio.getAttribute('aria-checked')).toBe('true');
    });
});

const { default: RecoveryInvitation } =
    await import('./RecoveryInvitation.svelte');

describe('RecoveryInvitation', () => {
    it('issues a recovery invitation for the account and shows the link once', async () => {
        const fetchMock = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        invitation: row(8, { kind: 'recovery' }),
                        url: `https://tesserarr.example.org/invite#${TOKEN}`,
                    }),
                    { status: 201 },
                ),
        );
        vi.stubGlobal('fetch', fetchMock);
        await render(RecoveryInvitation, { userId: 42, name: 'Sam' });

        button('invitations.recovery.issue').click();
        await settle();
        await settle();

        const init = (fetchMock.mock.calls[0] as unknown[])[1] as RequestInit;
        expect(JSON.parse(init.body as string)).toEqual({ days: 7, user: 42 });
        expect(document.body.textContent).toContain(TOKEN);
        button('invitations.done.close').click();
        await settle();
        expect(document.body.textContent).not.toContain(TOKEN);
    });

    it('shows a refusal instead of a link', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(async () => new Response('{}', { status: 403 })),
        );
        await render(RecoveryInvitation, { userId: 42, name: 'Sam' });

        button('invitations.recovery.issue').click();
        await settle();
        await settle();

        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'invitations.error.privilege_escalation',
        );
    });
});

describe('Invitations default validity', () => {
    it('preselects the instance default and sends it', async () => {
        const fetchMock = vi.fn(
            async () =>
                new Response(
                    JSON.stringify({
                        invitation: row(6),
                        url: `https://tesserarr.example.org/invite#${TOKEN}`,
                    }),
                    { status: 201 },
                ),
        );
        vi.stubGlobal('fetch', fetchMock);
        await render(Invitations, {
            invitations: [],
            canCreate: true,
            canRevoke: true,
            defaultDays: 14,
            now: () => NOW,
        });

        button('invitations.invite').click();
        await settle();
        expect(document.querySelectorAll('[role="radio"]')).toHaveLength(4);
        expect(
            document.querySelector('[role="radio"][aria-checked="true"]')
                ?.textContent,
        ).toContain('invitations.days');
        document
            .querySelector<HTMLFormElement>('form#invitation-form')!
            .requestSubmit();
        await settle();
        await settle();

        expect(
            JSON.parse(
                ((fetchMock.mock.calls[0] as unknown[])[1] as RequestInit)
                    .body as string,
            ),
        ).toEqual({ days: 14 });
    });
});
