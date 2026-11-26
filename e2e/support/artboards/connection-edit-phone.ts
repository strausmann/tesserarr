// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const connectionEditPhone: ArtboardSpec = {
    artboard: 'Connection-Edit-Phone.dc.html',
    title: 'Verbindung bearbeiten (Handy)',
    viewport: 'phone',
    expect: {
        headings: ['Sonarr HD', 'Verbindungstest', 'Gefahrenbereich'],
        controls: [
            'link:Zurück zu Verbindungen',
            'textbox:Anzeigename',
            'textbox:Kürzel',
            'textbox:Adresse',
            'password:Neuer API-Schlüssel',
            'button:Verbindung testen',
            'button:Verbindung entfernen',
            'button:Speichern',
        ],
        states: ['filled', 'error'],
    },
};
