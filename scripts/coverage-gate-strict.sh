#!/usr/bin/env bash
# Fail if any package falls below its coverage threshold.
#
# Usage: scripts/coverage-gate-strict.sh <coverage-profile> <package:threshold>...
#
# The thresholds come from the concept: authentication 90, mutating logic 85,
# business logic 80, adapters and boot code 60. A package with no threshold
# given is not checked — add it here when it gains real logic.

set -euo pipefail

if [[ $# -lt 2 ]]; then
    echo "usage: $0 <coverage-profile> <package:threshold>..." >&2
    exit 2
fi

profile="$1"
shift

if [[ ! -f "$profile" ]]; then
    echo "coverage profile not found: $profile" >&2
    exit 1
fi

failed=0

for spec in "$@"; do
    package="${spec%:*}"
    threshold="${spec##*:}"

    actual=$(go tool cover -func="$profile" \
        | grep -E "^[^[:space:]]*${package}/[^[:space:]]*\.go:" \
        | awk '{gsub(/%/, "", $NF); total += $NF; count++} END {if (count > 0) printf "%.1f", total / count; else print "0"}')

    if [[ -z "$actual" || "$actual" == "0" ]]; then
        echo "FAIL  ${package}: no coverage data — package missing or untested" >&2
        failed=1
        continue
    fi

    if awk -v a="$actual" -v t="$threshold" 'BEGIN { exit !(a < t) }'; then
        echo "FAIL  ${package}: ${actual}% < ${threshold}%" >&2
        failed=1
    else
        echo "ok    ${package}: ${actual}% >= ${threshold}%"
    fi
done

exit "$failed"
