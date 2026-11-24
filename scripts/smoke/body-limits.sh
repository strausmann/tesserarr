#!/usr/bin/env bash
# Smoke test of both request-body limit stages on a built image (Go audit #3).
#
#   scripts/smoke/body-limits.sh [image]     default image: tesserarr:dev
#
#   1. body above 2 MiB on a non-API path   -> 413 from Caddy (request_body max_size)
#   2. body above 64 KiB on /api/...        -> 413 from the app ({"error":"payload_too_large"})
#   2b. multipart above 64 KiB on /api/...  -> 415 (PHP parses it before the app; php://input is empty)
#   2c. chunked multipart on /api/...        -> 415 (411 only where a route allows multipart)
#   2d. body above 64 KiB on POST /login     -> 413 from the app (auth routes by name)
#   3. body sent at 1 byte/s                -> connection closed within 12 s (read_body 10s
#                                              plus Connection: close, see docker/Caddyfile)
#
# No database is needed: both stages answer before routing reaches one.
set -euo pipefail

image="${1:-tesserarr:dev}"
name="tesserarr-smoke-body-$$"
work="$(mktemp -d)"

cleanup() {
    docker rm -f "$name" >/dev/null 2>&1 || true
    rm -rf "$work"
}
trap cleanup EXIT

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

docker run -d --name "$name" -p 127.0.0.1::8080 \
    -e APP_ENV=production -e APP_DEBUG=false \
    -e "APP_KEY=base64:$(head -c 32 /dev/urandom | base64)" \
    "$image" >/dev/null

port="$(docker port "$name" 8080/tcp | head -n 1 | sed 's/.*://')"
base="http://127.0.0.1:${port}"

for _ in $(seq 1 100); do
    curl --silent --output /dev/null "$base/up" && break
    sleep 0.2
done
curl --silent --output /dev/null "$base/up" || fail "server did not come up"

# 1. Caddy stage
head -c $((2 * 1024 * 1024 + 1)) /dev/zero >"$work/over-2mib"
code="$(curl --silent --output "$work/caddy.out" --write-out '%{http_code}' \
    -H 'Content-Type: application/octet-stream' --data-binary @"$work/over-2mib" "$base/smoke")"
[ "$code" = 413 ] || fail "body above 2 MiB on a non-API path: expected 413 from Caddy, got $code"
if grep -q payload_too_large "$work/caddy.out"; then
    fail "the 413 above 2 MiB came from the app, not from Caddy"
fi
echo "ok: body above 2 MiB -> 413 from Caddy"

# 2. App stage
head -c 70000 /dev/zero | tr '\0' a | sed 's/^/{"pad":"/; s/$/"}/' >"$work/over-64kib.json"
code="$(curl --silent --output "$work/app.out" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' --data-binary @"$work/over-64kib.json" "$base/api/v1/smoke")"
[ "$code" = 413 ] || fail "body above 64 KiB on /api: expected 413 from the app, got $code"
grep -q '"error":"payload_too_large"' "$work/app.out" || fail "the 413 on /api did not come from the app: $(cat "$work/app.out")"
echo "ok: body above 64 KiB on /api -> 413 from the app"

head -c 70000 /dev/urandom >"$work/blob.bin"
code="$(curl --silent --output "$work/multipart.out" --write-out '%{http_code}' \
    -F "blob=@$work/blob.bin" "$base/api/v1/smoke")"
[ "$code" = 415 ] || fail "multipart above 64 KiB on /api: expected 415, got $code"
echo "ok: multipart on /api -> 415"

code="$(curl --silent --output "$work/chunked.out" --write-out '%{http_code}' \
    -H 'Transfer-Encoding: chunked' -F "blob=@$work/blob.bin" "$base/api/v1/smoke")"
[ "$code" = 415 ] || fail "chunked multipart on /api: expected 415, got $code"
echo "ok: chunked multipart on /api -> 415"

code="$(curl --silent --output "$work/login.out" --write-out '%{http_code}' \
    -H 'Content-Type: application/json' --data-binary @"$work/over-64kib.json" "$base/login")"
[ "$code" = 413 ] || fail "body above 64 KiB on POST /login: expected 413, got $code"
grep -q '"error":"payload_too_large"' "$work/login.out" || fail "the 413 on /login did not come from the app"
echo "ok: body above 64 KiB on POST /login -> 413 from the app"

# 3. Slow body: 100 declared bytes, one per second, streamed from a
# generator. curl's own --limit-rate throttles the download of the response
# as well, so it would measure the client, not the server. A declared length
# without "Expect: 100-continue" is the case net/http keeps open longest.
set +e
elapsed="$({ for _ in $(seq 1 100); do printf a; sleep 1; done; } 2>/dev/null \
    | curl --silent --output /dev/null --write-out '%{time_total}' --max-time 30 -X POST \
        -H 'Content-Type: application/x-www-form-urlencoded' -H 'Content-Length: 100' \
        -H 'Transfer-Encoding:' -H 'Expect:' -T - "$base/smoke")"
status=$?
set -e
if [ "$status" -eq 28 ] || awk -v t="$elapsed" 'BEGIN { exit !(t > 12) }'; then
    fail "body at 1 byte/s: connection still open after ${elapsed}s (curl exit $status)"
fi
echo "ok: body at 1 byte/s -> connection closed after ${elapsed}s"
