// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const setup: ArtboardSpec = {
    artboard: 'Setup.dc.html',
    title: 'Einrichtung (Assistent)',
    viewport: 'desktop',
    expect: {
        headings: ['Einrichtung', 'Plex verbinden'],
        controls: [
            'link:Schließen',
            'button:Verbindung testen',
            'link:Später fortsetzen',
            'button:Überspringen',
        ],
        states: ['filled'],
    },
};
