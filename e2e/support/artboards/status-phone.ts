// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const statusPhone: ArtboardSpec = {
    artboard: 'Status-Phone.dc.html',
    title: 'Status (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Status', 'Satelliten', 'Dienste'],
        controls: ['button:Suche öffnen', 'button:Alle Dienste testen'],
        states: ['filled'],
    },
};
