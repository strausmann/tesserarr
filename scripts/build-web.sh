#!/usr/bin/env bash
# Builds the web interface's static export and copies it into
# internal/web/dist, replacing the placeholder that ships there in git (see
# internal/web/dist/index.html's own comment for why a placeholder exists at
# all). deploy/Dockerfile does the equivalent copy itself, inside the build
# container, and does not call this script — this exists for local
# development: running `go run ./cmd/tesserarr serve` against the REAL
# interface instead of the placeholder, without a full Docker build.
#
# Usage: ./scripts/build-web.sh
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$repo_root"

pnpm --dir web install --frozen-lockfile
pnpm --dir web run build

rm -rf internal/web/dist
mkdir -p internal/web/dist
cp -r web/out/. internal/web/dist/

echo "internal/web/dist now holds the real built interface." >&2
echo "Run 'git checkout -- internal/web/dist' to restore the placeholder before committing." >&2
