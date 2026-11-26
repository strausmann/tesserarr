// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const connectionEditSyncthing: ArtboardSpec = {
    artboard: 'Connection-Edit-Syncthing.dc.html',
    title: 'Verbindung bearbeiten (Syncthing Keller)',
    viewport: 'desktop',
    expect: {
        headings: [
            'Syncthing Keller',
            'Instanz im Überblick',
            'Verbindungstest',
            'Ordner auf dieser Instanz',
            'Gefahrenbereich',
        ],
        controls: [
            'textbox:Anzeigename',
            'textbox:Kürzel',
            'textbox:Adresse',
            'group:Ausführungsort',
            'radio:Zentrale',
            'radio:Über Satellit Keller',
            'button:Speichern',
            'link:Abbrechen',
            'button:Verbindung testen',
            'switch:Nur mit Problemen',
            'button:Verbindung entfernen',
        ],
        states: ['filled'],
    },
};
