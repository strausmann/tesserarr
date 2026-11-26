// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const settingsGeneral: ArtboardSpec = {
    artboard: 'Settings-General.dc.html',
    title: 'Einstellungen: Allgemein',
    viewport: 'desktop',
    expect: {
        headings: [
            'Allgemein',
            'Instanz',
            'Region',
            'Darstellung und Vorgaben',
            'Versionswarnungen',
            'Über die Umgebung gesetzt',
        ],
        controls: [
            'textbox:Anzeigename',
            'combobox:Zeitzone',
            'textbox:Gültigkeit neuer Einladungen (Tage)',
            'textbox:Aufbewahrung des Protokolls (Tage)',
            'button:Je Dienst abweichend',
            'button:Speichern',
            'button:Verwerfen',
        ],
        states: ['filled'],
    },
};
