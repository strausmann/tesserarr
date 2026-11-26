// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const usersPhone: ArtboardSpec = {
    artboard: 'Users-Phone.dc.html',
    title: 'Nutzer (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Nutzer', 'Alle Nutzer'],
        controls: ['button:Einladen'],
        states: ['filled'],
    },
};
