// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const friendHomePhone: ArtboardSpec = {
    artboard: 'Friend-Home-Phone.dc.html',
    title: 'Sicht eines Freundes (Handy)',
    viewport: 'phone',
    expect: {
        headings: [
            'Katalog',
            'Was Alex mit dir teilt',
            'Deine Freigaben',
            'Meine Token',
        ],
        controls: ['searchbox:In den Freigaben suchen'],
        states: ['filled'],
    },
};
