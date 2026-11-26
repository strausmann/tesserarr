// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

// German label for every sidebar group. A group whose label is missing here fails the build
// (see group() in astro.config.mjs), so the German sidebar can never fall back to English.
export const sidebarLabels = {
  Install: "Installieren",
  Using: "Benutzen",
  Admin: "Verwalten",
  Integrations: "Integrationen",
  Reference: "Referenz",
  Developers: "Entwickeln",
};
