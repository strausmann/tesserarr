// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const audit: ArtboardSpec = {
    artboard: 'Audit.dc.html',
    title: 'Protokoll',
    viewport: 'desktop',
    expect: {
        headings: ['Protokoll'],
        controls: [
            'combobox:Zeitraum',
            'combobox:Wer',
            'searchbox:Was',
            'group:Herkunft',
            'table:Protokolleinträge',
            'navigation:Seiten',
            'link:Neuere',
            'link:Ältere',
        ],
        states: ['filled'],
    },
};
