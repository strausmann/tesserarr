---
title: Version and updates
description: Where the running version is shown, what "New version available" means and what the release check sends.
---

## The running version

The foot of the navigation shows the version Tesserarr runs, for example `Tesserarr 0.1.0 · About and licences`. Select
it to open **About Tesserarr**: the version, the licence with a link to the source code, and the licences of the fonts.

## "New version available"

When the operator updates Tesserarr while a tab is open, that tab still runs the interface of the old version. Every
answer of the server names the version it runs, and the tab compares it with its own. When they differ:

- a box **Version … is ready** appears in the foot of the navigation, and
- a notice **New version available** appears at the bottom of the screen.

Nothing reloads by itself, so nothing you typed is lost. **Reload** loads the new version. **Later** closes the notice for
that version in every tab of the browser; the box in the navigation stays until you reload, and a newer version opens the
notice again.

An open tab asks the server for its version every five minutes, when you come back to the tab and when the network is
back. The next page you open after an update loads the new version anyway.

## Newer releases (operator)

Under **About Tesserarr** the operator also sees whether a newer release has been published. The scheduler asks the
GitHub releases of Tesserarr every six hours for the newest release that is not a prerelease. The request carries no
token, no cookie, no address and no version of your installation, only the name `Tesserarr` as the user agent, and gives
up after five seconds. Without a network the last known answer stays; nothing in the interface fails. Only the version
number of the answer is used.

To switch the check off, set `TESSERARR_RELEASE_CHECK=false` (see
[configuration](/tesserarr/install/configuration/)). Tesserarr then sends nothing and shows nothing about releases.
`artisan tesserarr:release-check` runs the check once by hand.
