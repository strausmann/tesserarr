// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const connectionsPhone: ArtboardSpec = {
    artboard: 'Connections-Phone.dc.html',
    title: 'Verbindungen (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Verbindungen'],
        controls: [],
        states: ['filled'],
    },
};
