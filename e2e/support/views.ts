// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

/**
 * Every view of the interface that the UI gate checks (ADR-0006). A task that
 * builds or changes a view adds it here together with its artboard: the name
 * of the artboard in the mockup canvas and what that artboard shows.
 */

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

export type GatedView = {
    name: string;
    path: string;
    as: Role;
    artboard: string;
    expect: ArtboardExpectation;
};

// The two views of the starter kit stand for a guest and a signed-in page
// until the Tesserarr views replace them (tasks 7, 8 and 11).
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
        expect: {
            headings: [],
            controls: ['button:Toggle sidebar'],
            states: ['filled'],
        },
    },
];

/** The viewports of the gate: three widths, plus a phone with the keyboard open. */
export const gateViewports = [
    { name: '360', width: 360, height: 800 },
    { name: '768', width: 768, height: 1024 },
    { name: '1440', width: 1440, height: 900 },
    { name: '360x420 (keyboard)', width: 360, height: 420 },
] as const;

export const themes = ['light', 'dark'] as const;
export type Theme = (typeof themes)[number];
