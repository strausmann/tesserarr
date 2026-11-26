// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const users: ArtboardSpec = {
    artboard: 'Users.dc.html',
    title: 'Nutzer',
    viewport: 'desktop',
    expect: {
        headings: ['Nutzer', 'Alle Nutzer'],
        controls: [
            'button:Einladen',
            'table:Alle Nutzer',
            'table:Offene Einladungen',
        ],
        states: ['filled'],
    },
};
