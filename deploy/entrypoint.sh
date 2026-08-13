#!/bin/sh
# Start Tesserarr, optionally behind the secret service.
#
# Without INFISICAL_ADDRESS, INFISICAL_CLIENT_ID and INFISICAL_CLIENT_SECRET the
# service starts straight away with whatever environment variables are present —
# an image that runs without any secret service, for operators who run none.
#
# With them set, a two-step login runs. The second step is NOT skippable: a
# single call that only exports the credentials and invokes the command falls
# back to an interactive login prompt instead of using them.
set -eu

if [ -n "${INFISICAL_ADDRESS:-}" ] && [ -n "${INFISICAL_CLIENT_ID:-}" ] && [ -n "${INFISICAL_CLIENT_SECRET:-}" ]; then
    # --plain prints the token and nothing else; --silent suppresses update
    # notices. The value never reaches stdout, a log or a file: it goes straight
    # into the environment of the following call.
    INFISICAL_TOKEN=$(infisical login \
        --method=universal-auth \
        --domain="$INFISICAL_ADDRESS" \
        --client-id="$INFISICAL_CLIENT_ID" \
        --client-secret="$INFISICAL_CLIENT_SECRET" \
        --silent --plain)
    export INFISICAL_TOKEN

    exec infisical run \
        --env="${INFISICAL_ENV:?INFISICAL_ENV is required when the secret service is configured}" \
        --path="${INFISICAL_PATH:?INFISICAL_PATH is required when the secret service is configured}" \
        -- /usr/local/bin/tesserarr "$@"
fi

exec /usr/local/bin/tesserarr "$@"
