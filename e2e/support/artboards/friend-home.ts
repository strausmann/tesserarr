// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const friendHome: ArtboardSpec = {
    artboard: 'Friend-Home.dc.html',
    title: 'Sicht eines Freundes (Rolle Nutzer)',
    viewport: 'desktop',
    expect: {
        headings: [
            'Was Alex mit dir teilt',
            'Deine Freigaben',
            'Meine Token',
            'Mein Konto',
        ],
        controls: [
            'searchbox:In den Freigaben suchen',
            'radio:Liste',
            'radio:Kacheln',
            'link:Konto verwalten',
        ],
        states: ['filled'],
    },
};
