#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Install the pinned Vale release into .vale/bin (git-ignored), verified against
# the SHA-256 of the release asset. No `curl | sh`. Linux x86-64 only; elsewhere
# install Vale from https://github.com/errata-ai/vale/releases.
#
# Usage: scripts/vale_setup.sh      then run  .vale/bin/vale $(scripts/vale_scope.sh)
set -euo pipefail

VALE_VERSION="3.26.0"
VALE_SHA256="22aa69a556558e4a9dfc63c17030777383d8d3c48dcdfad655fa98e839d666ed"

root="$(cd "$(dirname "$0")/.." && pwd)"
work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT

curl --fail --silent --show-error --location --retry 3 --output "$work/vale.tar.gz" \
  "https://github.com/errata-ai/vale/releases/download/v${VALE_VERSION}/vale_${VALE_VERSION}_Linux_64-bit.tar.gz"
echo "${VALE_SHA256}  $work/vale.tar.gz" | sha256sum --check --quiet - || { echo "checksum mismatch: vale" >&2; exit 1; }

mkdir -p "$root/.vale/bin"
tar -xzf "$work/vale.tar.gz" -C "$root/.vale/bin" vale
"$root/.vale/bin/vale" --version
