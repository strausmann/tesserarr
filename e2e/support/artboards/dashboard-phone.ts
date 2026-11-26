// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const dashboardPhone: ArtboardSpec = {
    artboard: 'Dashboard-Phone.dc.html',
    title: 'Startseite (Handy)',
    viewport: 'phone',
    expect: {
        headings: [
            'Startseite',
            'Einrichtung',
            'Zustand',
            'Offene Einladungen',
            'Zuletzt im Protokoll',
        ],
        controls: [
            'button:Einrichtung ausblenden',
            'progressbar:Einrichtung',
            'link:Weiter einrichten',
            'link:Alle Details auf der Statusseite',
            'link:Ganzes Protokoll',
        ],
        states: ['filled'],
    },
};
