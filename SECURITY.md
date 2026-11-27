<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Security Policy

We work to keep Tesserarr secure, but issues can still slip through. If you believe you have
found a security issue, please tell us. We want to work with you to resolve it promptly.

## Reporting a Vulnerability

**Please do not open a public issue for security problems.** That exposes users before a fix
exists.

Use one of these private channels:

- **GitHub private vulnerability reporting:** the "Report a vulnerability" button on the
  [Security tab](https://github.com/strausmann/tesserarr/security) of the repository.
- **Email:** **security@strausmann.ch**. This inbox exists for security reports only.

If the report is sensitive, please encrypt it (see below). We support coordinated disclosure and
will credit you if you wish.

## Encryption (PGP)

Encrypt sensitive reports to our security key:

- **Fingerprint:** `3EE0 4713 5125 2BF2 C6BE FB1B BB40 F221 7A21 F210`
- **Address:** `security@strausmann.ch`
- **Get the key** from this repository at [`.well-known/security.asc`](.well-known/security.asc),
  or from one of these public key servers:
  - [keys.openpgp.org](https://keys.openpgp.org/search?q=security%40strausmann.ch)
  - [keys.mailvelope.com](https://keys.mailvelope.com/pks/lookup?op=get&search=security%40strausmann.ch)
  - [pgpkeys.eu](https://pgpkeys.eu/pks/lookup?search=0x3EE0471351252BF2C6BEFB1BBB40F2217A21F210&fingerprint=on&op=index)
  - [keyserver.ubuntu.com](https://keyserver.ubuntu.com/pks/lookup?search=0x3EE0471351252BF2C6BEFB1BBB40F2217A21F210&fingerprint=on&op=index)

Always compare the fingerprint before you use a key. If you have your own key, include your public
key or its fingerprint so we can reply encrypted.

## What to include

To help us reproduce and fix quickly:

- A clear description of the flaw and its impact.
- Step-by-step reproduction.
- The affected version(s) or commit.
- The environment where it occurs.
- Any suggested fix or workaround.

## Disclosure policy

- Tell us as soon as possible after you discover a potential issue. We will make every effort to
  resolve it quickly.
- Give us a reasonable amount of time to fix the issue before you disclose it to the public or a
  third party. We may disclose an issue before it is fixed when that protects users.
- Make a good-faith effort to avoid privacy violations, destruction of data, and interruption or
  degradation of any service. Only interact with accounts and data you own, or with the explicit
  permission of the owner.

## Our commitment

Once you report, we will:

- Confirm receipt within a couple of business days.
- Investigate and verify the issue.
- Develop and release a fix as quickly as is practical.
- Keep you informed throughout and coordinate the disclosure timing with you.
- Publish a GitHub security advisory for confirmed issues, request a CVE where appropriate, and
  mark the fix in the release notes.

## Scope

**In scope:** security issues in the current release and the default branch of Tesserarr
(https://github.com/strausmann/tesserarr).

**Out of scope:**

- Issues already reported in the issue tracker.
- Issues in third-party dependencies that are already reported to their maintainers. Report new
  ones to the maintainers directly.
- The services Tesserarr connects to (Syncthing, Sonarr, Radarr, Prowlarr, Plex, an OpenID
  Connect provider, a mail server). Report their flaws to their maintainers.
- The public transparency log entry of a signed image: the keyless cosign signature writes a
  Rekor entry that names the repository and the commit. That is by design.
- The infrastructure this project may be deployed on.
- Attacks that require physical access to a user's device.
- Vulnerabilities in outdated versions.
- Missing best practices that do not lead to a vulnerability. You may still report them as a
  normal issue.

While researching, please do not:

- run denial-of-service attacks,
- send spam,
- use social engineering (including phishing) against maintainers, contributors or users.

Thank you for helping keep Tesserarr and its users safe.
