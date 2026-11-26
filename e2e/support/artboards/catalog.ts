// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const catalog: ArtboardSpec = {
    artboard: 'Catalog.dc.html',
    title: 'Katalog (lesend)',
    viewport: 'desktop',
    expect: {
        headings: ['Katalog'],
        controls: ['searchbox:Titel suchen', 'radiogroup:Ansicht'],
        states: ['filled', 'empty'],
    },
    inState: {
        filled: {
            controls: [
                'navigation:Springen zu Anfangsbuchstabe',
                'list:Titel',
                'button:Weitere 50 laden',
            ],
        },
        empty: {
            headings: ['Noch nichts im Katalog'],
            controls: ['link:Plex verbinden', 'link:Medienfreigabe anlegen'],
        },
    },
};
