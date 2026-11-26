// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const menuPhone: ArtboardSpec = {
    artboard: 'Menu-Phone.dc.html',
    title: 'Menü (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Startseite'],
        controls: [
            'dialog:Navigation',
            'button:Menü schließen',
            'navigation:Hauptnavigation',
            'link:Abmelden',
        ],
        states: ['filled'],
    },
};
