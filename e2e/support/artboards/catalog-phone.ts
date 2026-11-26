// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const catalogPhone: ArtboardSpec = {
    artboard: 'Catalog-Phone.dc.html',
    title: 'Katalog (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Katalog'],
        controls: [
            'button:Suche öffnen',
            'list:Titel',
            'button:Weitere 50 laden',
        ],
        states: ['filled', 'empty'],
    },
};
