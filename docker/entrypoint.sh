#!/bin/sh
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later
# Tesserarr container entrypoint.
#
#   entrypoint.sh <web|worker|scheduler|reverb|satellite|artisan …>
#
# Each process role belongs to one instance role (TESSERARR_ROLE, default
# "central"); App\Runtime\ProcessRole holds the same mapping. A process on an
# instance of the other role is refused with exit code 64 instead of starting
# half-configured.
set -eu

role="${TESSERARR_ROLE:-central}"

need() {
    if [ "$role" != "$2" ]; then
        echo "entrypoint: process role '$1' needs TESSERARR_ROLE=$2 (this instance is '$role')" >&2
        exit 64
    fi
}

serve() {
    exec frankenphp run --config /etc/frankenphp/Caddyfile
}

command="${1:-web}"
if [ "$#" -gt 0 ]; then
    shift
fi

case "$command" in
    web)
        need web central
        serve
        ;;
    satellite)
        need satellite satellite
        serve
        ;;
    worker)
        need worker central
        exec php /app/artisan queue:work --sleep=3 --tries=3 --max-time=3600
        ;;
    scheduler)
        need scheduler central
        exec php /app/artisan schedule:work
        ;;
    reverb)
        need reverb central
        exec php /app/artisan reverb:start --host=0.0.0.0 --port="${REVERB_SERVER_PORT:-8081}"
        ;;
    artisan)
        exec php /app/artisan "$@"
        ;;
    frankenphp)
        # The image's default CMD: serve in whichever role this instance has.
        exec frankenphp "$@"
        ;;
    *)
        echo "entrypoint: unknown command '$command' (web, worker, scheduler, reverb, satellite, artisan)" >&2
        exit 64
        ;;
esac
