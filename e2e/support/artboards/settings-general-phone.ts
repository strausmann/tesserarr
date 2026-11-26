// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const settingsGeneralPhone: ArtboardSpec = {
    artboard: 'Settings-General-Phone.dc.html',
    title: 'Einstellungen: Allgemein (Handy)',
    viewport: 'phone',
    expect: {
        headings: [
            'Allgemein',
            'Instanz',
            'Region',
            'Darstellung und Vorgaben',
            'Über die Umgebung gesetzt',
        ],
        controls: [
            'textbox:Anzeigename',
            'textbox:Gültigkeit neuer Einladungen (Tage)',
            'textbox:Aufbewahrung des Protokolls (Tage)',
            'button:Speichern',
            'button:Verwerfen',
        ],
        states: ['filled'],
    },
};
