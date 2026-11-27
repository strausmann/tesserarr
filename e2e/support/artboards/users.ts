// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const users: ArtboardSpec = {
    artboard: 'Users.dc.html',
    title: 'Nutzer',
    viewport: 'desktop',
    // "Einladung widerrufen" and its danger zone live in the invitation dialog,
    // which the artboard opens only on demand (the empty-state fixture uses
    // them). "Einladen" is in the section head with invitations and in the
    // empty card without, so it is not in `expect`.
    expect: {
        headings: ['Nutzer', 'Alle Nutzer'],
        controls: ['table:Alle Nutzer'],
        states: ['filled', 'empty'],
    },
    inState: {
        filled: {
            controls: ['button:Einladen', 'table:Offene Einladungen'],
        },
        empty: {
            texts: ['Keine offenen Einladungen'],
        },
    },
};
