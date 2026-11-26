// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const dashboard: ArtboardSpec = {
    artboard: 'Dashboard.dc.html',
    title: 'Startseite Betreiber',
    viewport: 'desktop',
    expect: {
        headings: [
            'Startseite',
            'Einrichtung',
            'Zustand',
            'Offene Einladungen',
            'Zuletzt im Protokoll',
        ],
        controls: [
            'link:Status öffnen',
            'button:Einrichtung ausblenden',
            'progressbar:Einrichtung',
            'link:Weiter einrichten',
            'link:Zur Statusseite',
            'link:Ganzes Protokoll',
        ],
        states: ['filled'],
    },
};
