#!/usr/bin/env bash
# Smoke test of the runtime settings of a built image.
#
#   scripts/smoke/runtime.sh [image]     default image: tesserarr:dev
#
#   1. zend.exception_ignore_args is on (no arguments in stack traces)
#   2. the satellite role boots without a database (artisan about, exit 0)
#   3. the process runs as UID 10001
#   4. code is read-only for that user; storage/ and bootstrap/cache/ are writable
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
