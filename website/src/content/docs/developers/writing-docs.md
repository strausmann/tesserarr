---
title: Writing the docs
description: English and German together, relative links, and no data of a real installation.
---

The site lives in `website/` (Astro Starlight) and is published from `main` to GitHub Pages. Pull requests only build it.

- **Two languages, one pull request.** English lives at the root of `website/src/content/docs/`, German under `de/` with
  the same file name. A page in one language only fails the locale parity check
  (`node website/scripts/check-locale-parity.mjs`).
- **Every feature comes with its page**, in the pull request that builds it.
- **Internal links start with the base path** (`/tesserarr/install/configuration/`), with the trailing slash. The site
  sits under `/tesserarr/` on GitHub Pages until it has a domain. `starlight-links-validator` fails the build on a
  broken internal link, and on a relative one, which it cannot check.
- **Generated pages.** `reference/cli.md` comes from `scripts/cli_reference.py`, the API reference from
  `website/openapi/openapi.json`. Change the code, not the page.
- **No real data.** The site is public. Use `media.example.org`, the documentation ranges `192.0.2.0/24`,
  `198.51.100.0/24` and `203.0.113.0/24`, and the names `alice` and `bob`. The `real-data scan` job
  (`scripts/privacy_scan.py`) fails on private addresses, host names of an installation and personal e-mail addresses.

Build and check locally (Node 22.23.4 or newer, see `website/.nvmrc`):

```bash
npm --prefix website ci
npm --prefix website run build
node --test website/scripts/*.test.mjs && node website/scripts/check-locale-parity.mjs
python3 scripts/privacy_scan.py website/src website/public website/openapi
python3 scripts/privacy_scan.py --suffix .html --suffix .xml website/dist
```
