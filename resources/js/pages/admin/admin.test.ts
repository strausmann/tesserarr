// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import { flushSync, mount, tick, unmount } from 'svelte';
import type { Component } from 'svelte';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const inertia = vi.hoisted(() => ({
    page: { props: {} as Record<string, unknown>, url: '/' },
    router: { put: vi.fn(), post: vi.fn(), delete: vi.fn(), get: vi.fn() },
}));

vi.mock('@inertiajs/svelte', async (original) => ({
    ...(await original<Record<string, unknown>>()),
    page: inertia.page,
    router: inertia.router,
}));

const { default: Roles } = await import('./Roles.svelte');
const { default: Users } = await import('./Users.svelte');
const { default: Audit } = await import('./Audit.svelte');

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

const groups = [
    { key: 'users', permissions: ['users.view', 'users.manage', 'audit.view'] },
];
const roles = [
    {
        id: 1,
        name: 'operator',
        built_in: true,
        permissions: ['users.view', 'users.manage', 'audit.view'],
        members: 1,
    },
    {
        id: 3,
        name: 'helper',
        built_in: false,
        permissions: ['users.view'],
        members: 0,
    },
    { id: 2, name: 'user', built_in: true, permissions: [], members: 2 },
];

const boxes = () => [
    ...document.querySelectorAll<HTMLInputElement>('input[type="checkbox"]'),
];

describe('Roles', () => {
    it('offers boxes only for own roles and disables what the actor cannot grant', async () => {
        await render(Roles, {
            groups,
            roles,
            grantable: ['users.view', 'audit.view'],
        });

        expect(boxes().map((b) => b.dataset.box)).toEqual([
            '3:users.view',
            '3:users.manage',
            '3:audit.view',
        ]);
        expect(boxes().map((b) => b.disabled)).toEqual([false, true, false]);
        expect(boxes()[0].checked).toBe(true);
    });

    it('is one tab stop and moves with the arrow keys over grantable boxes only', async () => {
        await render(Roles, {
            groups,
            roles,
            grantable: ['users.view', 'audit.view'],
        });

        expect(boxes().map((b) => b.tabIndex)).toEqual([0, -1, -1]);
        boxes()[0].focus();
        boxes()[0].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'ArrowDown', bubbles: true }),
        );
        flushSync();

        expect(document.activeElement).toBe(boxes()[2]);
        expect(boxes().map((b) => b.tabIndex)).toEqual([-1, -1, 0]);

        boxes()[2].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Home', bubbles: true }),
        );
        flushSync();
        expect(document.activeElement).toBe(boxes()[0]);
    });

    it('saves only the roles that changed, with their ticked permissions', async () => {
        await render(Roles, {
            groups,
            roles,
            grantable: ['users.view', 'audit.view'],
        });

        const save = [...document.querySelectorAll('button')].find(
            (b) => b.textContent?.trim() === 'roles.save',
        )!;
        save.click();
        expect(inertia.router.put).not.toHaveBeenCalled();

        boxes()[2].click();
        flushSync();
        save.click();

        expect(inertia.router.put).toHaveBeenCalledTimes(1);
        expect(inertia.router.put.mock.calls[0][0]).toBe('/roles/3');
        expect(inertia.router.put.mock.calls[0][1]).toEqual({
            permissions: ['users.view', 'audit.view'],
        });
    });
});

const accounts = [
    {
        id: 1,
        username: 'alice',
        display_name: 'Alice',
        contact_email: null,
        locked_at: null,
        role: 'operator',
        identities: [],
        self: true,
    },
    {
        id: 2,
        username: 'bob',
        display_name: '',
        contact_email: 'bob@example.org',
        locked_at: null,
        role: 'user',
        identities: [
            {
                method: 'oidc',
                provider_key: 'example',
                provider_email: 'bob@idp.example.net',
            },
        ],
        self: false,
    },
];

describe('Users', () => {
    it('shows actions only with users.manage and never a lock for the own account', async () => {
        await render(
            Users,
            { users: accounts, roles: ['operator', 'user'] },
            { permissions: ['users.view'] },
        );
        expect(document.querySelectorAll('select')).toHaveLength(0);

        void unmount(app!);
        document.body.innerHTML = '';
        await render(
            Users,
            { users: accounts, roles: ['operator', 'user'] },
            { permissions: ['users.view', 'users.manage'] },
        );

        expect(document.querySelectorAll('select')).toHaveLength(2);
        const lock = [...document.querySelectorAll('button')].filter((b) =>
            b.textContent?.includes('users.lock'),
        );
        expect(lock).toHaveLength(1);
        lock[0].click();
        expect(inertia.router.post).toHaveBeenCalledWith(
            '/users/2/lock',
            {},
            { preserveScroll: true },
        );
    });

    it('saves the chosen role of a row', async () => {
        await render(
            Users,
            { users: accounts, roles: ['operator', 'user'] },
            { permissions: ['users.manage'] },
        );

        const select = document.querySelector<HTMLSelectElement>('#role-2')!;
        select.value = 'operator';
        select.dispatchEvent(new Event('change', { bubbles: true }));
        flushSync();
        const save = [...document.querySelectorAll('button')].filter(
            (b) => b.textContent?.trim() === 'users.save_role',
        )[1];
        save.click();

        expect(inertia.router.put).toHaveBeenCalledWith(
            '/users/2/role',
            { role: 'operator' },
            { preserveScroll: true },
        );
    });

    it('labels the account address and the provider address apart', async () => {
        await render(
            Users,
            { users: accounts, roles: ['operator', 'user'] },
            { permissions: [] },
        );

        expect(document.body.textContent).toContain('bob@example.org');
        expect(document.body.textContent).toMatch(
            /users\.provider_email:\s+bob@idp\.example\.net/,
        );
    });
});

describe('Audit', () => {
    const props = {
        entries: [
            {
                id: 5,
                at: '2026-01-02T10:00:00+00:00',
                who: 'Alice',
                source: 'cli',
                action: 'user.lock',
                subject: 'bob',
                outcome: 'denied',
            },
        ],
        total: 1,
        newer: null,
        older: '/audit?before=5',
        filters: { period: '7d', source: 'all', who: null, q: null },
        people: [{ value: 'user:1', label: 'Alice' }],
    };

    it('applies the origin filter and keeps the others', async () => {
        await render(Audit, props);

        [...document.querySelectorAll('button')]
            .find((b) => b.textContent?.trim() === 'audit.source.cli')!
            .click();

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/audit',
            { period: '7d', source: 'cli' },
            expect.objectContaining({ preserveState: true }),
        );
    });

    it('applies the period and the person', async () => {
        await render(Audit, props);

        const [period, who] = [
            ...document.querySelectorAll<HTMLSelectElement>('select'),
        ];
        period.value = '30d';
        period.dispatchEvent(new Event('change', { bubbles: true }));
        who.value = 'user:1';
        who.dispatchEvent(new Event('change', { bubbles: true }));

        expect(inertia.router.get).toHaveBeenNthCalledWith(
            1,
            '/audit',
            { period: '30d', source: 'all' },
            expect.anything(),
        );
        expect(inertia.router.get).toHaveBeenNthCalledWith(
            2,
            '/audit',
            { period: '7d', source: 'all', who: 'user:1' },
            expect.anything(),
        );
    });

    it('marks a refused entry and keeps the missing page link inert', async () => {
        await render(Audit, props);

        expect(document.querySelector('.tr-tag--bad')?.textContent).toContain(
            'audit.outcome.denied',
        );
        const newer = [...document.querySelectorAll('a')].find(
            (a) => a.textContent?.trim() === 'audit.newer',
        )!;
        expect(newer.getAttribute('aria-disabled')).toBe('true');
        const click = new MouseEvent('click', {
            bubbles: true,
            cancelable: true,
        });
        newer.dispatchEvent(click);
        expect(click.defaultPrevented).toBe(true);
    });
});

const button = (text: string) =>
    [...document.querySelectorAll('button')].find(
        (b) => b.textContent?.trim() === text,
    )!;

describe('Roles, saving several roles', () => {
    const two = [
        ...roles,
        { id: 4, name: 'second', built_in: false, permissions: [], members: 0 },
    ];

    it('saves one role after the other, each after the previous one succeeded', async () => {
        const pending: (() => void)[] = [];
        inertia.router.put.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess?: () => void },
            ) => pending.push(() => options.onSuccess?.()),
        );
        await render(Roles, {
            groups,
            roles: two,
            grantable: ['users.view', 'audit.view'],
        });

        const box = (key: string) =>
            boxes().find((b) => b.dataset.box === key)!;
        box('3:audit.view').click();
        box('4:users.view').click();
        flushSync();
        button('roles.save').click();

        expect(inertia.router.put).toHaveBeenCalledTimes(1);
        expect(inertia.router.put.mock.calls[0][0]).toBe('/roles/3');
        pending.shift()!();
        expect(inertia.router.put).toHaveBeenCalledTimes(2);
        expect(inertia.router.put.mock.calls[1]).toEqual([
            '/roles/4',
            { permissions: ['users.view'] },
            expect.objectContaining({ preserveState: true }),
        ]);
    });

    it('stops when a save is refused', async () => {
        inertia.router.put.mockImplementation(() => {});
        await render(Roles, {
            groups,
            roles: two,
            grantable: ['users.view', 'audit.view'],
        });

        boxes()
            .find((b) => b.dataset.box === '3:audit.view')!
            .click();
        boxes()
            .find((b) => b.dataset.box === '4:users.view')!
            .click();
        flushSync();
        button('roles.save').click();

        expect(inertia.router.put).toHaveBeenCalledTimes(1);
    });
});

describe('Roles, dialogs and errors', () => {
    it('creates a role from the dialog and deletes one after asking', async () => {
        inertia.router.post.mockImplementation(
            (
                _url: string,
                _data: unknown,
                options: { onSuccess?: () => void },
            ) => options.onSuccess?.(),
        );
        await render(
            Roles,
            { groups, roles, grantable: ['users.view'] },
            { errors: { permissions: 'refused' } },
        );

        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'refused',
        );
        expect(
            document.querySelectorAll('[role="img"]').length,
        ).toBeGreaterThan(0);

        button('roles.create').click();
        flushSync();
        const name = document.querySelector<HTMLInputElement>(
            '[role="dialog"] input',
        )!;
        name.value = 'helpers';
        name.dispatchEvent(new Event('input', { bubbles: true }));
        flushSync();
        [...document.querySelectorAll('[role="dialog"] button')]
            .find((b) => b.textContent?.trim() === 'roles.create')!
            .dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(inertia.router.post).toHaveBeenCalledWith(
            '/roles',
            { name: 'helpers', permissions: [] },
            expect.anything(),
        );

        button('roles.delete').click();
        flushSync();
        const confirm = [
            ...document.querySelectorAll('[role="dialog"] button'),
        ].find((b) => b.textContent?.trim() === 'roles.delete')!;
        confirm.dispatchEvent(new MouseEvent('click', { bubbles: true }));
        expect(inertia.router.delete).toHaveBeenCalledWith(
            '/roles/3',
            expect.anything(),
        );
    });

    it('ignores keys other than the arrows, Home and End', async () => {
        await render(Roles, {
            groups,
            roles,
            grantable: ['users.view', 'audit.view'],
        });

        boxes()[0].focus();
        boxes()[0].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'End', bubbles: true }),
        );
        flushSync();
        expect(document.activeElement).toBe(boxes()[2]);
        boxes()[2].dispatchEvent(
            new KeyboardEvent('keydown', { key: 'a', bubbles: true }),
        );
        expect(document.activeElement).toBe(boxes()[2]);
    });
});

describe('Users, states', () => {
    it('shows a locked account with an unlock button and the error of a refused change', async () => {
        const locked = {
            ...accounts[1],
            locked_at: '2026-01-01T00:00:00+00:00',
            role: 'helper',
        };
        await render(
            Users,
            {
                users: [accounts[0], locked],
                roles: ['operator', 'helper', 'user'],
            },
            {
                permissions: ['users.manage'],
                errors: { lock: 'last operator' },
            },
        );

        expect(document.querySelector('[role="alert"]')?.textContent).toContain(
            'last operator',
        );
        expect(document.querySelector('.tr-tag--bad')?.textContent).toContain(
            'users.locked',
        );
        [...document.querySelectorAll('button')]
            .find((b) => b.textContent?.includes('users.unlock'))!
            .click();
        expect(inertia.router.delete).toHaveBeenCalledWith('/users/2/lock', {
            preserveScroll: true,
        });
    });

    it('shows the empty state without accounts and the role of an account without a role', async () => {
        await render(Users, { users: [], roles: [] }, { permissions: [] });
        expect(document.body.textContent).toContain('users.empty.title');

        void unmount(app!);
        document.body.innerHTML = '';
        await render(
            Users,
            { users: [{ ...accounts[0], role: null }], roles: [] },
            { permissions: [] },
        );
        expect(document.body.textContent).toContain('users.no_role');
    });
});

describe('Audit, more', () => {
    const base = {
        entries: [] as never[],
        total: 0,
        newer: '/audit?after=9',
        older: null,
        filters: { period: '24h', source: 'ui', who: 'cli', q: 'role' },
        people: [],
    };

    it('shows the empty state and searches with Enter', async () => {
        await render(Audit, base);

        expect(document.body.textContent).toContain(
            'dashboard.audit.empty.title',
        );
        const search = document.querySelector<HTMLInputElement>(
            'input[type="search"]',
        )!;
        expect(search.value).toBe('role');
        search.value = 'user.lock';
        search.dispatchEvent(new Event('input', { bubbles: true }));
        flushSync();
        search.dispatchEvent(
            new KeyboardEvent('keydown', { key: 'Enter', bubbles: true }),
        );

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/audit',
            { period: '24h', source: 'ui', who: 'cli', q: 'user.lock' },
            expect.anything(),
        );
    });

    it('clears the person filter', async () => {
        await render(Audit, base);

        const who = document.querySelectorAll<HTMLSelectElement>('select')[1];
        who.value = '';
        who.dispatchEvent(new Event('change', { bubbles: true }));

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/audit',
            { period: '24h', source: 'ui', q: 'role' },
            expect.anything(),
        );
    });
});

describe('Audit, form and entries without a subject', () => {
    it('searches when the filter form is sent and shows an entry without a subject', async () => {
        await render(Audit, {
            entries: [
                {
                    id: 7,
                    at: '2026-01-02T10:00:00+00:00',
                    who: 'ops',
                    source: 'ui',
                    action: 'role.create',
                    subject: null,
                    outcome: 'done',
                },
            ],
            total: 1,
            newer: null,
            older: null,
            filters: { period: '7d', source: 'all', who: null, q: null },
            people: [],
        });

        expect(document.querySelector('.tr-tag--bad')).toBeNull();
        document
            .querySelector('form')!
            .dispatchEvent(
                new Event('submit', { bubbles: true, cancelable: true }),
            );

        expect(inertia.router.get).toHaveBeenCalledWith(
            '/audit',
            { period: '7d', source: 'all' },
            expect.anything(),
        );
    });
});
