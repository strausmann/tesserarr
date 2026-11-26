// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const settingsAuth: ArtboardSpec = {
    artboard: 'Settings-Auth.dc.html',
    title: 'Einstellungen: Anmeldung',
    viewport: 'desktop',
    expect: {
        headings: [
            'Anmeldung',
            'Zweiter Faktor',
            'Anmeldewege',
            'OIDC-Anbieter',
            'Konten und Einladungen',
        ],
        controls: [
            'button:Anbieter hinzufügen',
            'textbox:Anzeigename auf der Anmeldeseite',
            'textbox:Aussteller-Adresse (Issuer)',
            'button:Anbieter speichern',
            'button:Speichern',
        ],
        states: ['filled'],
    },
};
