// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const shares: ArtboardSpec = {
    artboard: 'Shares.dc.html',
    title: 'Medienfreigaben & Freigaben',
    viewport: 'desktop',
    expect: {
        headings: [
            'Medienfreigaben',
            'Freigaberegeln in „Serien“',
            'Ordner durchsuchen',
        ],
        controls: [
            'button:Medienfreigabe anlegen',
            'combobox:Für Nutzer',
            'table:Freigaberegeln',
            'searchbox:In ganz „Serien“ suchen',
            'searchbox:Auf dieser Ebene filtern',
            'combobox:Sortierung',
            'checkbox:Alle sichtbaren auswählen',
            'button:Weitere 50 laden',
        ],
        states: ['filled', 'empty'],
    },
};
