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

export type NavItem = { label: string; href: string };
export type NavGroup = { label: string | null; items: NavItem[] };

const operator: NavGroup[] = [
    {
        label: 'nav.group.overview',
        items: [
            { label: 'nav.home', href: '/dashboard' },
            { label: 'nav.status', href: '/status' },
        ],
    },
    {
        label: 'nav.group.media',
        items: [
            { label: 'nav.catalog', href: '/catalog' },
            { label: 'nav.media_shares', href: '/media-shares' },
        ],
    },
    {
        label: 'nav.group.operations',
        items: [
            { label: 'nav.connections', href: '/connections' },
            { label: 'nav.satellites', href: '/satellites' },
        ],
    },
    {
        label: 'nav.group.administration',
        items: [
            { label: 'nav.settings', href: '/settings/general' },
            { label: 'nav.users', href: '/users' },
            { label: 'nav.roles', href: '/roles' },
            { label: 'nav.tokens', href: '/tokens' },
            { label: 'nav.audit', href: '/audit' },
            { label: 'nav.login_methods', href: '/settings/sign-in' },
        ],
    },
];

const friend: NavGroup[] = [
    {
        label: null,
        items: [
            { label: 'nav.catalog', href: '/shared' },
            { label: 'nav.my_tokens', href: '/tokens' },
            { label: 'nav.account', href: '/settings/profile' },
        ],
    },
];

/** The navigation of an audience; a guest has none. */
export function navigationFor(audience: Audience): NavGroup[] {
    switch (audience) {
        case 'operator':
            return operator;
        case 'friend':
            return friend;
        default:
            return [];
    }
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
