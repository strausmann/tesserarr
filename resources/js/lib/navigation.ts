// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * The main navigation, separately for a friend and for the operator
 * (Friend-Home.dc.html and Dashboard.dc.html). The server says which one a
 * user gets (Inertia prop `audience`, App\Http\Ui\Audience). Labels are keys
 * of lang/*.json. Entries the canvas marks as a preview of 0.2.0 or later are
 * not listed.
 *
 * Most targets are built by later tasks of 0.1.0 (status 23, catalogue 21,
 * shares 17, connections 19, satellites 18, settings 8b, users and roles 8,
 * tokens 9, audit log 8, sign-in methods 13–16); their paths are fixed here so
 * the frame does not change when they arrive.
 */
export type Audience = 'guest' | 'friend' | 'operator';

/** `permission`: the item shows only when the account holds it (prop `permissions`). */
export type NavItem = { label: string; href: string; permission?: string };
export type NavGroup = { label: string | null; items: NavItem[] };

const operator: NavGroup[] = [
    {
        label: 'nav.group.overview',
        items: [
            { label: 'nav.home', href: '/dashboard' },
            { label: 'nav.status', href: '/status', permission: 'status.view' },
        ],
    },
    {
        label: 'nav.group.media',
        items: [
            {
                label: 'nav.catalog',
                href: '/catalog',
                permission: 'catalog.view_all',
            },
            {
                label: 'nav.media_shares',
                href: '/media-shares',
                permission: 'shares.manage',
            },
        ],
    },
    {
        label: 'nav.group.operations',
        items: [
            {
                label: 'nav.connections',
                href: '/connections',
                permission: 'connections.view',
            },
            {
                label: 'nav.satellites',
                href: '/satellites',
                permission: 'satellites.manage',
            },
        ],
    },
    {
        label: 'nav.group.administration',
        items: [
            {
                label: 'nav.settings',
                href: '/settings/general',
                permission: 'settings.manage',
            },
            { label: 'nav.users', href: '/users', permission: 'users.view' },
            { label: 'nav.roles', href: '/roles', permission: 'roles.manage' },
            {
                label: 'nav.tokens',
                href: '/tokens',
                permission: 'tokens.manage_own',
            },
            { label: 'nav.audit', href: '/audit', permission: 'audit.view' },
            {
                label: 'nav.login_methods',
                href: '/settings/sign-in',
                permission: 'login_methods.manage',
            },
        ],
    },
];

const friend: NavGroup[] = [
    {
        label: null,
        items: [
            {
                label: 'nav.catalog',
                href: '/shared',
                permission: 'catalog.browse',
            },
            {
                label: 'nav.my_tokens',
                href: '/tokens',
                permission: 'tokens.manage_own',
            },
            { label: 'nav.account', href: '/account' },
        ],
    },
];

/**
 * The navigation of an audience; a guest has none. With `permissions` (the
 * Inertia prop, decided by the Authorizer), items the account cannot open
 * are left out, and so is a group left empty: an own role with part of the
 * operator's permissions sees only its part.
 */
export function navigationFor(
    audience: Audience,
    permissions?: string[],
): NavGroup[] {
    const groups =
        audience === 'operator'
            ? operator
            : audience === 'friend'
              ? friend
              : [];

    if (permissions === undefined) {
        return groups;
    }

    return groups
        .map((group) => ({
            ...group,
            items: group.items.filter(
                (item) =>
                    item.permission === undefined ||
                    permissions.includes(item.permission),
            ),
        }))
        .filter((group) => group.items.length > 0);
}

/** The home page of an audience: where the logo leads. */
export function homeFor(audience: Audience): string {
    return audience === 'friend' ? '/shared' : '/dashboard';
}

/** Is `href` the page at `path` (or a page below it)? */
export function isCurrent(href: string, path: string): boolean {
    const clean = path.split(/[?#]/)[0].replace(/\/+$/, '') || '/';

    return clean === href || clean.startsWith(`${href}/`);
}
