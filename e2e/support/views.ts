// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Every view of the interface that the UI gate checks (ADR-0006). A task that
 * builds or changes a view adds it here together with its artboard: the name
 * of the artboard in the mockup canvas and what that artboard shows.
 */

import { audit } from './artboards/audit';
import { dashboard } from './artboards/dashboard';
import { friendHome } from './artboards/friend-home';
import { roles } from './artboards/roles';
import { users } from './artboards/users';

export type Role = 'guest' | 'friend' | 'operator';

export type ArtboardState = 'empty' | 'error' | 'loading' | 'filled';

/**
 * What an artboard shows, found by role and accessible name (`getByRole`), not
 * by pixels. A control is written `role:name`, for example `button:Log in` or
 * `password:Password` (a password field has no ARIA role, so `password:` finds
 * it by its label). Each state needs a fixture in `stateFixtures`
 * (e2e/support/artboard.ts) that puts the view into it.
 */
export type ArtboardExpectation = {
    headings: string[];
    controls: string[];
    states: ArtboardState[];
};

/**
 * What only some states of an artboard show (the list when there is content,
 * the hint when there is none), keyed by state. `assertArtboardStates` checks
 * these in their state only; `ArtboardExpectation` holds what every state
 * shows. A key must be one of the view's `states`.
 */
export type ArtboardStateElements = Partial<
    Record<
        ArtboardState,
        { headings?: string[]; controls?: string[]; texts?: string[] }
    >
>;

export type GatedView = {
    name: string;
    path: string;
    as: Role;
    artboard: string;
    expect: ArtboardExpectation;
    inState?: ArtboardStateElements;
    /**
     * The browser language of the check (Accept-Language). The artboards are
     * German, so a view checked against their labels runs in `de-DE`.
     */
    locale?: string;
};

// The sign-in of the starter kit stands for a guest page until task 11
// rebuilds it. Dashboard and the friend's home are the views of task 7: each
// takes the part of its artboard that exists without data, named below with
// the task that adds the rest.
export const gatedViews: GatedView[] = [
    {
        name: 'Login',
        path: '/login',
        as: 'guest',
        artboard: 'Login.dc.html',
        expect: {
            headings: ['Log in to your account'],
            controls: [
                'textbox:Username or email',
                'password:Password',
                'checkbox:Remember me',
                'button:Log in',
            ],
            states: ['empty', 'error'],
        },
    },
    {
        name: 'Dashboard',
        path: '/dashboard',
        as: 'operator',
        artboard: 'Dashboard.dc.html',
        locale: 'de-DE',
        // The artboard without "Einrichtung" and its controls (setup
        // checklist, task 22a) and without "Status öffnen" / "Zur Statusseite"
        // (status page, task 23) and "Ganzes Protokoll" (audit log, task 8).
        expect: {
            headings: without(dashboard.expect.headings, ['Einrichtung']),
            controls: [],
            states: ['empty'],
        },
        inState: {
            empty: {
                texts: [
                    'Noch nichts zu überwachen',
                    'Keine offenen Einladungen',
                    'Noch nichts im Protokoll',
                ],
            },
        },
    },
    {
        name: 'Friend home',
        path: '/shared',
        as: 'friend',
        artboard: 'Friend-Home.dc.html',
        locale: 'de-DE',
        // The artboard names the operator ("Was Alex mit dir teilt"); who
        // shares what is known once there are grants (task 21a), until then
        // the title is general. The search and the list/tile switch need
        // shares (21a); "Meine Token" shows its empty state (task 9).
        expect: {
            headings: [
                'Was mit dir geteilt wird',
                ...without(friendHome.expect.headings, [
                    'Was Alex mit dir teilt',
                ]),
            ],
            controls: ['link:Konto verwalten'],
            states: ['empty'],
        },
        inState: {
            empty: {
                texts: ['Noch nichts mit dir geteilt', 'Noch keine Token'],
            },
        },
    },
    // Task 8. The user list without the invitations (button "Einladen",
    // table "Offene Einladungen", their empty state: task 10).
    {
        name: 'Users',
        path: '/users',
        as: 'operator',
        artboard: users.artboard,
        locale: 'de-DE',
        expect: { ...users.expect, states: ['filled'] },
    },
    {
        name: 'Roles',
        path: '/roles',
        as: 'operator',
        artboard: roles.artboard,
        locale: 'de-DE',
        expect: roles.expect,
    },
    {
        name: 'Audit log',
        path: '/audit',
        as: 'operator',
        artboard: audit.artboard,
        locale: 'de-DE',
        expect: audit.expect,
    },
];

function without(list: string[], drop: string[]): string[] {
    return list.filter((item) => !drop.includes(item));
}

/** The viewports of the gate: three widths, plus a phone with the keyboard open. */
export const gateViewports = [
    { name: '360', width: 360, height: 800 },
    { name: '768', width: 768, height: 1024 },
    { name: '1440', width: 1440, height: 900 },
    { name: '360x420 (keyboard)', width: 360, height: 420 },
] as const;

export const themes = ['light', 'dark'] as const;
export type Theme = (typeof themes)[number];
