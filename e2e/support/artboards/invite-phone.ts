// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

import type { ArtboardSpec } from './spec';

export const invitePhone: ArtboardSpec = {
    artboard: 'Invite-Phone.dc.html',
    title: 'Einladung einlösen (Handy, 5 Schritte)',
    viewport: 'phone',
    expect: {
        headings: ['Willkommen bei Medien-NAS'],
        controls: ['progressbar:Fortschritt', 'button:Los geht’s'],
        states: ['filled'],
    },
};
