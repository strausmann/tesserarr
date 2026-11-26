// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const roles: ArtboardSpec = {
    artboard: 'Roles.dc.html',
    title: 'Rollen & Rechte',
    viewport: 'desktop',
    expect: {
        headings: ['Rollen und Rechte', 'Rechte je Rolle', 'Gefahrenbereich'],
        controls: [
            'button:Rolle anlegen',
            'button:Änderungen speichern',
            'button:Rolle „Mitbetreuer“ löschen',
        ],
        states: ['filled'],
    },
};
