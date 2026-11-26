// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const login: ArtboardSpec = {
    artboard: 'Login.dc.html',
    title: 'Anmeldung (Desktop)',
    viewport: 'desktop',
    expect: {
        headings: ['Anmelden'],
        controls: [
            'link:DE',
            'link:EN',
            'button:Mit Passkey anmelden',
            'textbox:Benutzername oder E-Mail',
            'password:Passwort',
            'button:Anmelden',
            'button:Mit Plex anmelden',
            'link:Ich habe einen Einladungslink',
        ],
        states: ['empty'],
    },
};
