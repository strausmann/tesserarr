// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const userDetail: ArtboardSpec = {
    artboard: 'User-Detail.dc.html',
    title: 'Nutzer: Sam',
    viewport: 'desktop',
    expect: {
        headings: [
            'Sam',
            'Rolle',
            'Anmeldewege',
            'Zweiter Faktor (MFA)',
            'Kontakt',
            'Medienfreigaben, die Sam sieht',
            'Letzte Protokolleinträge',
            'Gefahrenbereich',
        ],
        controls: [
            'combobox:Rolle von Sam',
            'link:Rollen und Rechte',
            'button:Rolle speichern',
            'textbox:E-Mail-Adresse von Sam',
            'button:Speichern und Bestätigungslink senden',
            'link:Freigaben verwalten',
            'link:Im Protokoll filtern',
            'button:Sam sperren',
            'button:Einladung ausstellen',
            'button:Nutzer entfernen',
        ],
        states: ['filled'],
    },
};
