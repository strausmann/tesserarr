<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Mockups for 0.1.0

The interface of 0.1.0 is built against a mockup canvas: one artboard per view, drawn at 1440 px for the desktop and at
390 × 844 px for a phone. A view counts as done when it shows the headings, controls and states of its artboard. The UI
gate in `e2e/` checks that ([ADR-0006](../../decisions/0006-ui-gate-as-a-hard-gate.md)); it does not compare pixels.

- **Canvas:** <https://claude.ai/artifact/BnkgLrD51eyesMWreULXPs> (private; the maintainer grants access)
- **Previews in this repository:** the screenshots under [`docs/images/mockups/`](../../images/mockups/) and in the
  [README](../../../README.md#preview-mockups)

The artboards and their example data are in German, the language the mockups were drawn in. The interface is
bilingual; the expectations below hold the German labels.

## Approval

| What | Approved by the maintainer |
| --- | --- |
| All artboards for 0.1.0 | 24 November 2026 |
| `Catalog.dc.html` and `Catalog-Phone.dc.html` after the change to a list view with an A–Z jump bar and no posters | 26 November 2026 |
| Raising three colours to WCAG AA (see "Contrast") | 26 November 2026 |

With the catalogue approved, no artboard of 0.1.0 has a reservation left; the catalogue part of task 21 may start.

## Style, logo and tokens

The artboard `Main.dc.html` ("Stil & Logo") sets the type, colours, radii and spacing. They are the Labelyard tokens,
unchanged: one design line across Labelyard, BambuBridge and Tesserarr, with amber as the accent.

| Canvas | Repository |
| --- | --- |
| Colours light and dark, type scale, 4 px grid, radii 6/10/14, focus ring | [`resources/css/tokens.css`](../../../resources/css/tokens.css), pinned by `resources/js/lib/tokens.test.ts` |
| Type: Atkinson Hyperlegible and Atkinson Hyperlegible Mono | loaded at build time through `vite.config.ts` (Bunny Fonts, served from the app); licence SIL OFL 1.1, the licence files of both fonts are in [`public/licenses/`](../../../public/licenses/) and served under `/licenses/` |
| The mark: four tiles in ink, amber and stamp blue | [`public/logo.svg`](../../../public/logo.svg), [`public/logo-dark.svg`](../../../public/logo-dark.svg) |
| The app symbol: the mark on an amber tile | [`public/logo-app.svg`](../../../public/logo-app.svg), [`public/apple-touch-icon.png`](../../../public/apple-touch-icon.png) |
| The 16 px favicon with narrower joints | [`public/favicon.svg`](../../../public/favicon.svg) (switches to the dark colours with the system theme) |

Where the repository departs from the canvas, and why:

- **Token names carry the prefix `--tr-`.** The canvas names the roles `--bg`, `--accent` and so on. In the app, the
  starter-kit components still read variables of the same names with another meaning (`--accent` is their hover fill),
  so the roles are `--tr-bg`, `--tr-accent` and so on, as on the documentation website. `app.css` points the starter-kit
  variables at the roles; the brand accent is reachable as `primary` until task 7 replaces those components.
- **`favicon.ico` shows the app symbol, not the bare mark.** The ICO file has no dark variant, and the ink tiles of the
  bare mark disappear on a dark tab bar. Browsers that read `favicon.svg` get the mark of the canvas.
- **`apple-touch-icon.png` has square corners.** iOS rounds the icon itself and fills transparent corners with black.
- **Three colours are darker than in the canvas (lighter in dark mode)**, so that the interface reaches WCAG AA. See
  "Contrast" below.

## Contrast

The maintainer decided on 26 November 2026 that the interface meets WCAG AA. Three colours of the canvas (and of
Labelyard) fall short of it, so they change in lightness only; the hue stays.

| Token | Use | Canvas | Repository | Contrast canvas → repository (on bg / surface / sunken) |
| --- | --- | --- | --- | --- |
| `--tr-warn`, light | state text, 13 px | `#b5651b` | `#9f5918` | 3.95 / 4.23 / 3.65 → 4.88 / 5.23 / 4.51 (needs 4.5) |
| `--tr-ok`, light | state text, 13 px | `#2e7a4e` | `#2d784d` | 4.76 / 5.10 / 4.40 → 4.89 / 5.24 / 4.52 (needs 4.5) |
| `--tr-line-strong`, light | border of fields and buttons | `#cfc8ba` | `#8d8678` | 1.51 / 1.62 / 1.40 → 3.29 / 3.52 / 3.04 (needs 3, WCAG 1.4.11) |
| `--tr-line-strong`, dark | border of fields and buttons | `#4a443a` | `#756f65` | 1.88 / 1.73 / 1.57 → 3.63 / 3.34 / 3.03 (needs 3, WCAG 1.4.11) |

Every other text role already reaches 4.5:1 on all three grounds in both themes (the smallest is `--tr-ink-3` on
`--tr-sunken` in dark mode, 4.65:1). `resources/js/lib/tokens.test.ts` computes these ratios for every text role and
the control border on every ground and fails below 4.5:1 or 3:1. The darker border also makes dividers drawn with
`--tr-line-strong` stronger; plain dividers use `--tr-line`, which is unchanged. Labelyard still has the canvas values;
the documentation website (task 5) too.

## Artboards of 0.1.0

Each artboard has its expectation (headings, controls and states) in `e2e/support/artboards/`. The UI task that builds a
view takes it into `gatedViews` (`e2e/support/views.ts`). An expectation lists only what the artboard shows in its
default state. It leaves out the app frame and every section that the canvas marks as a preview of 0.2.0 or later. The
details are in [`spec.ts`](../../../e2e/support/artboards/spec.ts).

| Artboard | Title in the canvas | Drawn for | Expectation |
| --- | --- | --- | --- |
| `Main.dc.html` | `Stil & Logo` | 1440 px | none, this is the style sheet (see above) |
| `Login.dc.html` | `Anmeldung (Desktop)` | 1440 px | [`login.ts`](../../../e2e/support/artboards/login.ts) |
| `Login-Phone.dc.html` | `Anmeldung (Handy)` | 390 × 844 | [`login-phone.ts`](../../../e2e/support/artboards/login-phone.ts) |
| `Invite-Phone.dc.html` | `Einladung einlösen (Handy, 5 Schritte)` | 390 × 844 | [`invite-phone.ts`](../../../e2e/support/artboards/invite-phone.ts) |
| `Dashboard.dc.html` | `Startseite Betreiber` | 1440 px | [`dashboard.ts`](../../../e2e/support/artboards/dashboard.ts) |
| `Dashboard-Phone.dc.html` | `Startseite (Handy)` | 390 × 844 | [`dashboard-phone.ts`](../../../e2e/support/artboards/dashboard-phone.ts) |
| `Menu-Phone.dc.html` | `Menü (Handy)` | 390 × 844 | [`menu-phone.ts`](../../../e2e/support/artboards/menu-phone.ts) |
| `Status.dc.html` | `Status & Verbindungen (live)` | 1440 px | [`status.ts`](../../../e2e/support/artboards/status.ts) |
| `Status-Phone.dc.html` | `Status (Handy)` | 390 × 844 | [`status-phone.ts`](../../../e2e/support/artboards/status-phone.ts) |
| `Connections.dc.html` | `Verbindungen (Übersicht)` | 1440 px | [`connections.ts`](../../../e2e/support/artboards/connections.ts) |
| `Connections-Phone.dc.html` | `Verbindungen (Handy)` | 390 × 844 | [`connections-phone.ts`](../../../e2e/support/artboards/connections-phone.ts) |
| `Connection-Edit.dc.html` | `Verbindung bearbeiten (Sonarr HD)` | 1440 px | [`connection-edit.ts`](../../../e2e/support/artboards/connection-edit.ts) |
| `Connection-Edit-Phone.dc.html` | `Verbindung bearbeiten (Handy)` | 390 × 844 | [`connection-edit-phone.ts`](../../../e2e/support/artboards/connection-edit-phone.ts) |
| `Connection-Edit-Syncthing.dc.html` | `Verbindung bearbeiten (Syncthing Keller)` | 1440 px | [`connection-edit-syncthing.ts`](../../../e2e/support/artboards/connection-edit-syncthing.ts) |
| `Satellites.dc.html` | `Satelliten` | 1440 px | [`satellites.ts`](../../../e2e/support/artboards/satellites.ts) |
| `Setup.dc.html` | `Einrichtung (Assistent)` | 1440 px | [`setup.ts`](../../../e2e/support/artboards/setup.ts) |
| `Setup-Phone.dc.html` | `Einrichtung (Handy)` | 390 × 844 | [`setup-phone.ts`](../../../e2e/support/artboards/setup-phone.ts) |
| `Shares.dc.html` | `Medienfreigaben & Freigaben` | 1440 px | [`shares.ts`](../../../e2e/support/artboards/shares.ts) |
| `Catalog.dc.html` | `Katalog (lesend)` | 1440 px | [`catalog.ts`](../../../e2e/support/artboards/catalog.ts) |
| `Catalog-Phone.dc.html` | `Katalog (Handy)` | 390 × 844 | [`catalog-phone.ts`](../../../e2e/support/artboards/catalog-phone.ts) |
| `Friend-Home.dc.html` | `Sicht eines Freundes (Rolle Nutzer)` | 1440 px | [`friend-home.ts`](../../../e2e/support/artboards/friend-home.ts) |
| `Friend-Home-Phone.dc.html` | `Sicht eines Freundes (Handy)` | 390 × 844 | [`friend-home-phone.ts`](../../../e2e/support/artboards/friend-home-phone.ts) |
| `Users.dc.html` | `Nutzer` | 1440 px | [`users.ts`](../../../e2e/support/artboards/users.ts) |
| `Users-Phone.dc.html` | `Nutzer (Handy)` | 390 × 844 | [`users-phone.ts`](../../../e2e/support/artboards/users-phone.ts) |
| `User-Detail.dc.html` | `Nutzer: Sam` | 1440 px | [`user-detail.ts`](../../../e2e/support/artboards/user-detail.ts) |
| `Roles.dc.html` | `Rollen & Rechte` | 1440 px | [`roles.ts`](../../../e2e/support/artboards/roles.ts) |
| `Tokens.dc.html` | `API-Token` | 1440 px | [`tokens.ts`](../../../e2e/support/artboards/tokens.ts) |
| `Account.dc.html` | `Mein Konto` | 1440 px | [`account.ts`](../../../e2e/support/artboards/account.ts) |
| `Account-Phone.dc.html` | `Mein Konto (Handy)` | 390 × 844 | [`account-phone.ts`](../../../e2e/support/artboards/account-phone.ts) |
| `Settings-Auth.dc.html` | `Einstellungen: Anmeldung` | 1440 px | [`settings-auth.ts`](../../../e2e/support/artboards/settings-auth.ts) |
| `Settings-General.dc.html` | `Einstellungen: Allgemein` | 1440 px | [`settings-general.ts`](../../../e2e/support/artboards/settings-general.ts) |
| `Settings-General-Phone.dc.html` | `Einstellungen: Allgemein (Handy)` | 390 × 844 | [`settings-general-phone.ts`](../../../e2e/support/artboards/settings-general-phone.ts) |
| `Audit.dc.html` | `Protokoll` | 1440 px | [`audit.ts`](../../../e2e/support/artboards/audit.ts) |

`Connection-Edit-Syncthing.dc.html` has no preview mark in its title, so it belongs to 0.1.0, but several of its
sections carry the badge `Vorschau 0.2.0`: provisioning, coupled devices, the tabs `Geräte`, `Einstellungen` and
`Ignorieren` and the device and folder dialogs. Those sections are not part of 0.1.0; the expectation leaves them out,
and the task that builds the view does not build them either.

When an artboard shows something only in one state (the list when there is content, the hint when there is none), the
expectation keeps it out of `expect` and lists it under `inState`, because the gate checks `expect` again in every
state.

The plan used working names for some artboards; the canvas names are the ones above.

| Working name in the plan | Artboard |
| --- | --- |
| `Friend-View.dc.html` | `Friend-Home.dc.html` |
| `Invite-Mobile.dc.html` | `Invite-Phone.dc.html` |
| `MediaShares-Grants.dc.html` | `Shares.dc.html` |
| `Settings-SignIn.dc.html` | `Settings-Auth.dc.html` |
| `Status-Mobile.dc.html` | `Status-Phone.dc.html` |
| `Style.dc.html` | `Main.dc.html` |

## Artboards of later versions

The canvas also holds previews of later versions. They have no expectation for 0.1.0 and are not part of its
approval.

| Artboard | Title in the canvas | Version |
| --- | --- | --- |
| `Syncthing-Instances.dc.html` | `Syncthing-Instanzen` | 0.2.0 |
| `Syncthing-Instances-Phone.dc.html` | `Syncthing-Instanzen (Handy)` | 0.2.0 |
| `Syncthing-Transfers.dc.html` | `Syncthing-Übertragungen` | 0.2.0 |
| `Syncthing-Transfers-Phone.dc.html` | `Übertragungen (Handy)` | 0.2.0 |
| `Syncthing-Setup.dc.html` | `Syncthing einrichten` | 0.2.0 |
| `Syncthing-Setup-Phone.dc.html` | `Syncthing einrichten (Handy)` | 0.2.0 |
| `Connection-Edit-Transfer.dc.html` | `Verbindung bearbeiten (Syncthing Transfer)` | 0.2.0 |
| `Connection-Edit-Relay.dc.html` | `Verbindung bearbeiten (Syncthing-Relay)` | 0.2.0 |
| `Groups.dc.html` | `Nutzergruppen` | 0.2.0 |
| `Groups-Phone.dc.html` | `Nutzergruppen (Handy)` | 0.2.0 |
| `Group-Detail.dc.html` | `Nutzergruppe bearbeiten` | 0.2.0 |
| `Arr-Setup.dc.html` | `Sonarr, Radarr und Prowlarr anbinden` | 0.3.0 |
| `Arr-Setup-Phone.dc.html` | `Sonarr und Radarr anbinden (Handy)` | 0.3.0 |
| `Linked-Instances.dc.html` | `Verknüpfte Tesserarr-Instanzen` | 0.4.0 |
| `Linked-Instances-Phone.dc.html` | `Verknüpfte Instanzen (Handy)` | 0.4.0 |
| `Linked-Instance-Detail.dc.html` | `Verknüpfte Instanz` | 0.4.0 |
| `Linked-Instance-Detail-Phone.dc.html` | `Verknüpfte Instanz (Handy)` | 0.4.0 |
| `Instance-Link.dc.html` | `Tesserarr eines Freundes verknüpfen` | 0.4.0 |
| `Instance-Link-Phone.dc.html` | `Tesserarr verknüpfen (Handy)` | 0.4.0 |
| `Instance-Consent.dc.html` | `Freigabe bei der anderen Instanz` | 0.4.0 |
| `Instance-Consent-Phone.dc.html` | `Freigabe bei der anderen Instanz (Handy)` | 0.4.0 |
