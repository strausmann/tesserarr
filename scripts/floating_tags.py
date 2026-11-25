#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Which floating image tags a release build may move.

docker-publish.yml tags a stable release x.y.z also as `x.y`, `x` and `latest`.
A rebuild of an OLDER release (the recovery path in release.yml, a rerun after
a newer release exists) must not move them back. So each floating tag moves
only when the built version is the highest stable version of its scope:

    latest   the highest stable version overall
    x        the highest stable version with the same major
    x.y      the highest stable version with the same major and minor

Stable versions come from the git tags `vX.Y.Z` (pre-releases left out); the
built version counts even when its tag is not there yet.

    python3 scripts/floating_tags.py --version 1.0.1 [--repo DIR | --tags-from -]

prints `latest=…`, `major=…`, `minor=…` (true/false) for $GITHUB_OUTPUT.
Standard library only.
"""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from collections.abc import Iterable
from typing import Any, TextIO

_NUM = r"(0|[1-9][0-9]*)"
VERSION_RE = re.compile(rf"\A{_NUM}\.{_NUM}\.{_NUM}\Z")
PRE_RELEASE_RE = re.compile(rf"\A{_NUM}\.{_NUM}\.{_NUM}-[0-9A-Za-z.-]+\Z")
STABLE_TAG_RE = re.compile(rf"\Av{_NUM}\.{_NUM}\.{_NUM}\Z")


def _parse(version: str) -> tuple[int, int, int]:
    match = VERSION_RE.match(version)
    if not match:
        raise ValueError(f"not a stable version: {version!r}")
    return int(match.group(1)), int(match.group(2)), int(match.group(3))


def floating(version: str, tags: Iterable[str]) -> dict[str, bool]:
    """The floating tags the build of `version` may set."""
    if PRE_RELEASE_RE.match(version):
        return {"latest": False, "major": False, "minor": False}
    built = _parse(version)
    stable = {built}
    for tag in tags:
        match = STABLE_TAG_RE.match(tag.strip())
        if match:
            stable.add((int(match.group(1)), int(match.group(2)), int(match.group(3))))
    return {
        "latest": built == max(stable),
        "major": built == max(v for v in stable if v[0] == built[0]),
        "minor": built == max(v for v in stable if v[:2] == built[:2]),
    }


def git_tags(repo: str) -> list[str]:
    out = subprocess.run(
        ["git", "tag", "-l", "v*"], cwd=repo, check=True, capture_output=True, text=True
    ).stdout
    return out.splitlines()


def main(argv: list[str] | None = None, stdin: TextIO | Any = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--version", required=True)
    source = parser.add_mutually_exclusive_group()
    source.add_argument("--repo", default=".")
    source.add_argument("--tags-from", choices=["-"])
    args = parser.parse_args(argv)
    tags = (stdin or sys.stdin).read().splitlines() if args.tags_from else git_tags(args.repo)
    try:
        result = floating(args.version, tags)
    except ValueError as err:
        print(f"::error::{err}")
        return 1
    for key in ("latest", "major", "minor"):
        print(f"{key}={'true' if result[key] else 'false'}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
