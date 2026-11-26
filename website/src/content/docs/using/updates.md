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

## Newer releases (operator, opt-in)

Tesserarr can tell the operator under **About Tesserarr** whether a newer release has been published. The check is
**off by default**: as long as it is off, nothing leaves your instance, and the dialog says that the check is off.

To switch it on, set `TESSERARR_RELEASE_CHECK=true` (see [configuration](/tesserarr/install/configuration/)). The
scheduler of the central instance then asks the GitHub releases of Tesserarr every six hours for the newest release
that is not a prerelease. A satellite never asks.

- **What is sent:** a plain `GET` request with the user agent `Tesserarr`. No token, no cookie, no address and no version
  of your installation.
- **What is used:** only the version number of the answer, and at most 64 KiB of it are read. Name, text and links of
  the release are ignored; the link in the dialog is built by Tesserarr.
- **Without a network:** the request gives up after five seconds, the last known answer stays, and nothing in the
  interface fails.

The first answer comes with the next run of the scheduler, which can be up to six hours after the switch. To have it at
once, run the check one time by hand:

```bash
artisan tesserarr:release-check
```
