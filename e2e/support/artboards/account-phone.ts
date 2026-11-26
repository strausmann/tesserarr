// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const accountPhone: ArtboardSpec = {
    artboard: 'Account-Phone.dc.html',
    title: 'Mein Konto (Handy)',
    viewport: 'phone',
    expect: {
        headings: [
            'Mein Konto',
            'Passkeys',
            'Authenticator-App (TOTP)',
            'Wiederherstellungscodes',
            'E-Mail-Adresse',
            'Verbundene Anmeldewege',
        ],
        controls: [
            'button:Passkey hinzufügen',
            'button:Neu einrichten',
            'button:Entfernen',
            'button:Neue Codes erzeugen',
        ],
        states: ['filled'],
    },
};
