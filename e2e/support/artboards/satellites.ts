// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const satellites: ArtboardSpec = {
    artboard: 'Satellites.dc.html',
    title: 'Satelliten',
    viewport: 'desktop',
    expect: {
        headings: ['Satelliten', 'Registrierte Satelliten'],
        controls: [
            'button:Satellit registrieren',
            'table:Registrierte Satelliten',
            'button:Prüfauftrag senden',
        ],
        states: ['filled'],
    },
};
