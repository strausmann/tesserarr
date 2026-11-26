// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const setupPhone: ArtboardSpec = {
    artboard: 'Setup-Phone.dc.html',
    title: 'Einrichtung (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Einrichtung', 'Plex verbinden', 'Verbindungstest'],
        controls: [
            'button:Zurück',
            'button:Verbindung testen',
            'link:Später fortsetzen',
            'button:Überspringen',
        ],
        states: ['filled'],
    },
};
