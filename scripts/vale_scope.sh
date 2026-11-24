#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# Print the files Vale lints, one per line: the English Markdown of the
# repository and of the website. The German website pages are left out; they are
# German on purpose. Files come from `git ls-files`, so scratch files are not linted.
set -euo pipefail
cd "$(dirname "$0")/.."
git ls-files -- '*.md' '*.mdx' \
  | grep -vE '^website/src/content/docs/de/' \
  | grep -vE '^(LICENSES/|packages/[^/]+/vendor/)' \
  | grep -vE '^(CHANGELOG\.md|release-notes/)' || true
