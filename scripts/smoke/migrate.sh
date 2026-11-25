#!/usr/bin/env bash
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Smoke test of `artisan tesserarr:migrate` in a built image against
# PostgreSQL 18, with the image's own pg_dump (postgresql-client-18). pg_dump
# must not be older than the server, so both majors are checked first.
#
#   scripts/smoke/migrate.sh [image]     default image: tesserarr:dev
#
#   1. a fresh database: one backup pre-migrate-*.dump, mode 0600, owned by
#      UID 10001, readable by pg_restore --list; the schema exists afterwards
#   2. a second run: nothing pending, no new backup
#   3. an unwritable backup directory: exit code not 0 and nothing migrated
set -euo pipefail

image="${1:-tesserarr:dev}"
postgres_image='postgres:18.7-trixie@sha256:c699303802144847d238196173655dbbecf7a96aeca1565f2b02eb671a1f0b6b'
run_id="tesserarr-smoke-migrate-$$"
network="$run_id"
backups="$(mktemp -d)"

fail() {
    echo "FAIL: $*" >&2
    exit 1
}

# The exit code is the result of the checks above; cleaning up never changes it.
# The backups belong to UID 10001 of the image, and the runner may not delete
# them (on GitHub's runners it may not), so a container running as root empties
# the directories first; only the empty directories are removed on the host.
cleanup() {
    local rc=$?
    set +e
    docker rm -f "$run_id-db" >/dev/null 2>&1
    docker network rm "$network" >/dev/null 2>&1
    for dir in "$backups" "${blocked:-}"; do
        if [ -z "$dir" ] || [ ! -d "$dir" ]; then
            continue
        fi
        docker run --rm --user 0 -v "$dir":/d --entrypoint sh "$image" -c 'rm -rf /d/* /d/.[!.]*' >/dev/null 2>&1
        chmod 0700 "$dir" 2>/dev/null
        rmdir "$dir" 2>/dev/null || echo "warning: could not remove $dir" >&2
    done
    exit "$rc"
}
trap cleanup EXIT

docker network create "$network" >/dev/null
docker run -d --name "$run_id-db" --network "$network" \
    -e POSTGRES_DB=tesserarr -e POSTGRES_USER=tesserarr -e POSTGRES_PASSWORD=tesserarr \
    "$postgres_image" >/dev/null
# Over TCP: the temporary server of the image's initialisation listens on the
# socket only, so a ready TCP port is the final server.
for _ in $(seq 1 60); do
    if docker exec "$run_id-db" pg_isready -h 127.0.0.1 -U tesserarr -d tesserarr >/dev/null 2>&1; then break; fi
    sleep 1
done
docker exec "$run_id-db" pg_isready -h 127.0.0.1 -U tesserarr -d tesserarr >/dev/null || fail "PostgreSQL did not start"

server_major="$(docker exec "$run_id-db" psql -U tesserarr -d tesserarr -tAc 'show server_version_num' | cut -c1-2)"
[ "$server_major" = 18 ] || fail "the database is PostgreSQL $server_major, expected 18"
dump_major="$(docker run --rm --entrypoint pg_dump "$image" --version | sed -E 's/^pg_dump \(PostgreSQL\) ([0-9]+).*/\1/')"
[ "$dump_major" = 18 ] || fail "the image's pg_dump is $dump_major, expected 18"
echo "ok: PostgreSQL 18 server, pg_dump 18 in the image"

app_key="base64:$(head -c 32 /dev/urandom | base64)"
migrate() { # backup-dir [extra docker args...]
    local dir="$1"
    shift
    docker run --rm --network "$network" "$@" \
        -e APP_KEY="$app_key" -e TESSERARR_ROLE=central -e DB_CONNECTION=pgsql \
        -e DB_HOST="$run_id-db" -e DB_PORT=5432 -e DB_DATABASE=tesserarr \
        -e DB_USERNAME=tesserarr -e DB_PASSWORD=tesserarr -e CACHE_STORE=array \
        -v "$dir":/var/lib/tesserarr/backups \
        "$image" artisan tesserarr:migrate
}
tables() {
    docker exec "$run_id-db" psql -U tesserarr -d tesserarr -tAc \
        "select count(*) from information_schema.tables where table_schema = 'public'"
}

# The bind mount is created by the test, so it belongs to the runner: hand it
# to the image's user as an operator would (CONTRIBUTING.md).
docker run --rm -u 0 -v "$backups":/b --entrypoint chown "$image" 10001:10001 /b

migrate "$backups" >/dev/null || fail "tesserarr:migrate on a fresh database exited with $?"
[ "$(tables)" -gt 0 ] || fail "no table after the migration"
mapfile -t dumps < <(docker run --rm -v "$backups":/b --entrypoint sh "$image" -c 'cd /b && ls pre-migrate-*.dump')
[ "${#dumps[@]}" -eq 1 ] || fail "expected one backup, found ${#dumps[@]}"
leftovers="$(docker run --rm -v "$backups":/b --entrypoint sh "$image" -c 'ls /b | grep -c "\.partial$" || true')"
[ "$leftovers" -eq 0 ] || fail "a .partial file is left in the backup directory"
docker run --rm -v "$backups":/b --entrypoint sh "$image" -c "
    set -e
    f=/b/${dumps[0]}
    [ \"\$(stat -c '%a %u' \"\$f\")\" = '600 10001' ] || { echo \"mode/owner: \$(stat -c '%a %u' \"\$f\")\"; exit 1; }
    [ -s \"\$f\" ]
    pg_restore --list \"\$f\" >/dev/null
" || fail "the backup ${dumps[0]} is not a 0600 custom-format dump of UID 10001"
echo "ok: fresh database migrated after a 0600 custom-format backup (${dumps[0]})"

output="$(migrate "$backups")" || fail "the second run exited with $?"
grep -q 'Nothing to migrate' <<< "$output" || fail "the second run did not report 'Nothing to migrate'"
count="$(docker run --rm -v "$backups":/b --entrypoint sh "$image" -c 'ls /b/pre-migrate-*.dump | wc -l')"
[ "$count" -eq 1 ] || fail "the second run wrote a backup although nothing was pending ($count files)"
echo "ok: nothing pending, no new backup"

# A database with pending migrations and a backup directory the image cannot
# write to: the run must fail before it migrates anything.
docker exec "$run_id-db" psql -U tesserarr -d tesserarr -qc 'drop schema public cascade; create schema public;' >/dev/null 2>&1
blocked="$(mktemp -d)"
chmod 0555 "$blocked"
if migrate "$blocked" >/dev/null 2>&1; then
    fail "tesserarr:migrate succeeded without a writable backup directory"
fi
[ "$(tables)" -eq 0 ] || fail "tables were created although the backup failed"
echo "ok: no backup, no migration"
