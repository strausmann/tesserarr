<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# Tesserarr

**Share parts of your media library with friends over an existing Syncthing link.**

Your friends browse what you chose to share, request a title, and their own Sonarr or Radarr picks it up like any
other source. You decide who sees which library, how much they may request, and how long a file stays if nobody
collects it. Friends sign in with an invitation and a passkey; no torrents and no public index are involved.

**Status: early development.** There is no stable release yet. Expect breaking changes until `1.0.0`.

- Source code and issues: <https://github.com/strausmann/tesserarr>
- Documentation: in the repository, <https://github.com/strausmann/tesserarr#readme>

## Pull

The same multi-arch image is published to Docker Hub and the GitHub Container Registry:

```bash
docker pull docker.io/bstrausmann/tesserarr:dev
docker pull ghcr.io/strausmann/tesserarr:dev
```

Platforms: `linux/amd64` and `linux/arm64`.

## Tags

| Tag          | Example             | Use when                                                         |
| ------------ | ------------------- | ---------------------------------------------------------------- |
| `x.y.z`      | `0.1.0`             | You want full reproducibility                                    |
| `x.y`        | `0.1`               | Get bug fixes automatically                                      |
| `x`          | `1`                 | Stay on a major version, get features                            |
| `latest`     | most recent release | You are fine with anything new                                   |
| `dev`        | built from `main`   | Testing only; changes with every merge                           |
| `x.y.z-rc.N` | `0.1.0-rc.1`        | Preview of an upcoming release; removed once the release exists  |

Previews never get `latest`, `x.y` or `x`, so a preview can never become the default.

## Verify the signature

Every published image is signed without a key (Sigstore cosign, GitHub OIDC) and carries an SBOM and a build
provenance attestation:

```bash
cosign verify docker.io/bstrausmann/tesserarr:0.1.0-rc.1 \
  --certificate-identity-regexp 'https://github.com/strausmann/tesserarr/.*' \
  --certificate-oidc-issuer https://token.actions.githubusercontent.com
```

## Licence

GNU Affero General Public License v3.0 or later (AGPL-3.0-or-later). Sonarr, Radarr, Prowlarr, Plex and Syncthing
are trademarks of their owners; Tesserarr is an independent project.
