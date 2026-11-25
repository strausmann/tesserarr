#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Browser tests against the built image, as in CI and as in production.
#
#   docker build -f docker/Dockerfile --target e2e -t tesserarr:e2e .
#   npm ci
#   scripts/e2e/run.sh [image] [playwright arguments …]
#
# Starts PostgreSQL 18 and the image (stage `e2e`) on a private Docker network,
# migrates, waits for /up and runs the Playwright suite in the official
# Playwright container. The repository and its node_modules are mounted, so the
# @playwright/test of package-lock.json must match the container version.
# Reports land in playwright-report/ and test-results/. Everything started here
# is removed on exit.
set -euo pipefail

image="${1:-tesserarr:e2e}"
[ "$#" -gt 0 ] && shift

PLAYWRIGHT_IMAGE=mcr.microsoft.com/playwright:v1.64.1-noble@sha256:2f05f8a734509a7b3f26a912639488ee08f58a7b292d14d62271bcff9038768f
POSTGRES_IMAGE=postgres:18.7-trixie@sha256:c699303802144847d238196173655dbbecf7a96aeca1565f2b02eb671a1f0b6b

# The browsers come from the container, the test runner from node_modules:
# both must be the same Playwright release, or the suite fails with a missing
# browser executable that does not name the cause.
container_version="${PLAYWRIGHT_IMAGE#*:v}"
container_version="${container_version%%-*}"
if [ ! -f node_modules/@playwright/test/package.json ]; then
    echo "e2e: node_modules/@playwright/test is missing; run npm ci first" >&2
    exit 1
fi
npm_version="$(node -p "require('./node_modules/@playwright/test/package.json').version")"
if [ "$npm_version" != "$container_version" ]; then
    echo "e2e: @playwright/test is $npm_version (package-lock.json), the container is $container_version." >&2
    echo "e2e: update PLAYWRIGHT_IMAGE in scripts/e2e/run.sh (tag and digest) to v$npm_version." >&2
    exit 1
fi

run_id="tesserarr-e2e-$$"
net="$run_id"
db="$run_id-db"
app="$run_id-app"

cleanup() {
    docker rm -f "$app" "$db" >/dev/null 2>&1 || true
    docker network rm "$net" >/dev/null 2>&1 || true
}
trap cleanup EXIT

app_env=(
    -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)"
    -e APP_URL="http://$app:8080"
    -e DB_CONNECTION=pgsql -e DB_HOST="$db" -e DB_PORT=5432
    -e DB_DATABASE=tesserarr -e DB_USERNAME=tesserarr -e DB_PASSWORD=tesserarr
)

docker network create "$net" >/dev/null
docker run -d --name "$db" --network "$net" \
    -e POSTGRES_DB=tesserarr -e POSTGRES_USER=tesserarr -e POSTGRES_PASSWORD=tesserarr \
    "$POSTGRES_IMAGE" >/dev/null
# Over TCP: while the image initialises the database, a temporary server
# answers on the socket only and then restarts.
db_ready() { docker exec "$db" pg_isready -h 127.0.0.1 -U tesserarr -d tesserarr >/dev/null 2>&1; }
for _ in $(seq 1 60); do
    db_ready && break
    sleep 1
done
db_ready || { docker logs "$db" >&2; echo "e2e: PostgreSQL did not start" >&2; exit 1; }

docker run --rm --network "$net" "${app_env[@]}" "$image" artisan migrate --force --no-interaction
docker run -d --name "$app" --network "$net" "${app_env[@]}" "$image" >/dev/null
for _ in $(seq 1 60); do
    docker exec "$app" curl -sf -o /dev/null http://127.0.0.1:8080/up && break
    sleep 1
done
if ! docker exec "$app" curl -sf -o /dev/null http://127.0.0.1:8080/up; then
    docker logs "$app" >&2
    echo "e2e: the image did not answer /up" >&2
    exit 1
fi

status=0
docker run --rm --network "$net" --ipc=host \
    --user "$(id -u):$(id -g)" -e HOME=/tmp \
    -e CI="${CI:-}" -e E2E_BASE_URL="http://$app:8080" \
    -v "$PWD":/work -w /work \
    "$PLAYWRIGHT_IMAGE" npx playwright test "$@" || status=$?

if [ "$status" -ne 0 ]; then
    echo "e2e: application log" >&2
    docker logs --tail 200 "$app" >&2
fi
exit "$status"
