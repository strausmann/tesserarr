// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const account: ArtboardSpec = {
    artboard: 'Account.dc.html',
    title: 'Mein Konto',
    viewport: 'desktop',
    expect: {
        headings: [
            'Mein Konto',
            'Passkeys',
            'Authenticator-App (TOTP)',
            'Wiederherstellungscodes',
            'Verbundene Anmeldewege',
            'E-Mail-Adresse',
            'Anzeige',
        ],
        controls: [
            'button:Passkey hinzufügen',
            'button:Neu einrichten',
            'button:Entfernen',
            'button:Neue Codes erzeugen',
            'textbox:E-Mail (optional)',
            'button:Speichern',
            'radio:Deutsch',
            'radio:English',
        ],
        states: ['filled'],
    },
};
