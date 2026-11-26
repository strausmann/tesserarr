// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const connectionEdit: ArtboardSpec = {
    artboard: 'Connection-Edit.dc.html',
    title: 'Verbindung bearbeiten (Sonarr HD)',
    viewport: 'desktop',
    expect: {
        headings: ['Sonarr HD', 'Verbindungstest', 'Gefahrenbereich'],
        controls: [
            'textbox:Anzeigename',
            'textbox:Kürzel',
            'textbox:Adresse',
            'button:Ersetzen',
            'group:Ausführungsort',
            'radio:Zentrale',
            'radio:Über Satellit Keller',
            'button:Speichern',
            'link:Abbrechen',
            'button:Verbindung testen',
            'button:Verbindung entfernen',
        ],
        states: ['filled'],
    },
};
