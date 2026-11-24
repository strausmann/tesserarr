#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Smoke test of the runtime settings of a built image.
#
#   scripts/smoke/runtime.sh [image]     default image: tesserarr:dev
#
#   1. zend.exception_ignore_args is on (no arguments in stack traces)
#   2. the satellite role boots without a database (artisan about, exit 0)
#   3. the process runs as UID 10001
#   4. code is read-only for that user; storage/ and bootstrap/cache/ are writable
#   5. the sign-in of the browser tests is absent: no marker, no route file,
#      no controller, and /_testing/login/operator answers 404 even with
#      APP_ENV=testing and TESSERARR_E2E_LOGIN=true
set -euo pipefail

image="${1:-tesserarr:dev}"

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

value="$(docker run --rm --entrypoint php "$image" -r 'echo ini_get("zend.exception_ignore_args");')"
[ "$value" = 1 ] || fail "zend.exception_ignore_args is '$value', expected 1"
echo "ok: zend.exception_ignore_args = 1"

docker run --rm -e TESSERARR_ROLE=satellite "$image" artisan about >/dev/null \
    || fail "artisan about in the satellite role exited with $?"
echo "ok: satellite role boots (artisan about)"

uid="$(docker run --rm --entrypoint id "$image" -u)"
[ "$uid" = 10001 ] || fail "runs as UID $uid, expected 10001"
echo "ok: runs as UID 10001"

docker run --rm --entrypoint sh "$image" -c '
    for path in /app /app/app /app/vendor /app/config /app/public /app/public/build /app/bootstrap /app/artisan; do
        if [ -w "$path" ]; then echo "writable: $path"; exit 1; fi
    done
    if [ -n "$(find /app -path /app/storage -prune -o -path /app/bootstrap/cache -prune -o -user tesserarr -print -quit)" ]; then
        echo "owned by tesserarr outside storage/ and bootstrap/cache/"; exit 1
    fi
    for path in /app/storage/logs /app/storage/framework/views /app/bootstrap/cache; do
        if [ ! -w "$path" ]; then echo "not writable: $path"; exit 1; fi
    done
' || fail "file ownership: application code must be read-only for the runtime user"
echo "ok: code read-only, storage/ and bootstrap/cache/ writable"

docker run --rm --entrypoint sh "$image" -c '
    for path in /app/.e2e-image /app/routes/testing.php /app/app/Testing; do
        if [ -e "$path" ]; then echo "present: $path"; exit 1; fi
    done
    if grep -q E2eLoginController /app/vendor/composer/autoload_classmap.php; then
        echo "the class map lists E2eLoginController"; exit 1
    fi
' || fail "the sign-in of the browser tests is part of the image"
echo "ok: no e2e marker, route file or controller in the image"

# The settings an operator could set by mistake (TESSERARR_E2E_LOGIN switched
# the route on in an earlier design). No database is needed: an unregistered
# route answers 404 before anything touches it.
web="tesserarr-smoke-$$"
docker run -d --name "$web" -e APP_ENV=testing -e TESSERARR_E2E_LOGIN=true \
    -e APP_KEY="base64:$(head -c 32 /dev/urandom | base64)" "$image" >/dev/null
trap 'docker rm -f "$web" >/dev/null 2>&1 || true' EXIT
status=000
for _ in $(seq 1 30); do
    status="$(docker exec "$web" curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:8080/_testing/login/operator || true)"
    [ "$status" != 000 ] && break
    sleep 1
done
[ "$status" = 404 ] || fail "/_testing/login/operator answered $status with APP_ENV=testing, expected 404"
echo "ok: /_testing/login answers 404 with APP_ENV=testing and TESSERARR_E2E_LOGIN=true"
