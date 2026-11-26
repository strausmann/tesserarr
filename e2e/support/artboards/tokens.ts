// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const tokens: ArtboardSpec = {
    artboard: 'Tokens.dc.html',
    title: 'API-Token',
    viewport: 'desktop',
    expect: {
        headings: [
            'API-Token',
            'Neues Token',
            'Bestehende Token',
            'Gefahrenbereich',
        ],
        controls: [
            'textbox:Name',
            'group:Vorgabe',
            'group:Rechte',
            'link:Alle Rechte zeigen',
            'combobox:Ablauf',
            'button:Token erzeugen',
            'table:Bestehende Token',
            'button:Token widerrufen',
        ],
        states: ['filled'],
    },
};
