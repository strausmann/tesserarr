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
            'searchbox:In ganz „Serien“ suchen',
        ],
        states: ['filled', 'empty'],
    },
    inState: {
        filled: {
            // The detail of the selected rule sits next to the rule list.
            headings: ['Gefahrenbereich'],
            controls: [
                'table:Freigaberegeln',
                'button:Regel entfernen',
                'searchbox:Auf dieser Ebene filtern',
                'combobox:Sortierung',
                'checkbox:Alle sichtbaren auswählen',
                'button:Weitere 50 laden',
            ],
        },
        empty: {
            controls: ['link:Jetzt lesen lassen'],
        },
    },
};
