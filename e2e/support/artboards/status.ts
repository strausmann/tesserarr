// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const status: ArtboardSpec = {
    artboard: 'Status.dc.html',
    title: 'Status & Verbindungen (live)',
    viewport: 'desktop',
    expect: {
        headings: [
            'Status und Verbindungen',
            'Satelliten',
            'Dienste',
            'Selbsttest',
        ],
        controls: ['button:Alle testen'],
        states: ['filled'],
    },
};
