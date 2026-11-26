// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const connections: ArtboardSpec = {
    artboard: 'Connections.dc.html',
    title: 'Verbindungen (Übersicht)',
    viewport: 'desktop',
    expect: {
        headings: ['Verbindungen', 'Verbindungen nach Art'],
        controls: [],
        states: ['filled'],
    },
};
