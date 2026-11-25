#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""The base image pins of docker/Dockerfile.

The base images (FROM) are pinned by tag and digest in docker/base-images.env,
one ``NAME_IMAGE=repository:tag@sha256:<digest>`` per line, and nowhere else:
docker/Dockerfile declares them as ``ARG NAME_IMAGE`` without a default. The
file has no code owner, so a digest or patch update from Renovate merges after
green CI, while the Dockerfile itself stays owned (CONTRIBUTING.md,
"Dependencies and licences").

Every build passes the pins as build arguments:

    python3 scripts/base_images.py check          # the file, and the Dockerfile if there is one
    python3 scripts/base_images.py build-args     # KEY=VALUE lines (docker/build-push-action)
    python3 scripts/base_images.py cli            # --build-arg KEY=VALUE … for docker build
    python3 scripts/base_images.py github-output --name build-args   # multiline step output

Every command checks first and fails on a malformed pin or on a Dockerfile
that does not match: an image ARG with a default (a second pin Renovate does
not update), an image ARG without a pin, or a pin no ARG uses.
Standard library only.
"""

from __future__ import annotations

import argparse
import os
import re
import secrets
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
PINS_FILE = ROOT / "docker" / "base-images.env"
DOCKERFILE = ROOT / "docker" / "Dockerfile"

# repository[:port]/path:tag@sha256:digest. The tag is required: Renovate's
# docker versioning needs it to offer patch and minor updates, the digest pins
# the content. Nothing here can reach a shell as anything but one word.
_COMPONENT = r"[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*"
_REGISTRY = r"[a-z0-9]+(?:[.-][a-z0-9]+)*(?::[0-9]+)?"
PIN_RE = re.compile(
    rf"\A(?P<key>[A-Z][A-Z0-9_]*_IMAGE)="
    rf"(?P<value>(?:{_REGISTRY}/)?{_COMPONENT}(?:/{_COMPONENT})*"
    r":[A-Za-z0-9_][A-Za-z0-9._-]{0,127}@sha256:[0-9a-f]{64})\Z"
)
ARG_RE = re.compile(r"^\s*ARG\s+(?P<key>[A-Z][A-Z0-9_]*_IMAGE)(?P<default>=\S*)?\s*$", re.MULTILINE)


class PinError(ValueError):
    """A line of docker/base-images.env that is not a pin."""


def parse_pins(text: str) -> dict[str, str]:
    """The pins in file order. Comments (#) and blank lines are skipped."""
    pins: dict[str, str] = {}
    for number, line in enumerate(text.splitlines(), start=1):
        if not line.strip() or line.lstrip().startswith("#"):
            continue
        match = PIN_RE.match(line)
        if not match:
            raise PinError(f"line {number}: not NAME_IMAGE=repository:tag@sha256:<64 hex>")
        key = match.group("key")
        if key in pins:
            raise PinError(f"line {number}: {key} is listed twice")
        pins[key] = match.group("value")
    if not pins:
        raise PinError("no pin found")
    return pins


def check(pins: dict[str, str], dockerfile: str) -> list[str]:
    """Differences between the pins and the image ARGs of the Dockerfile."""
    errors: list[str] = []
    declared: list[str] = []
    for match in ARG_RE.finditer(dockerfile):
        key = match.group("key")
        if match.group("default") is not None:
            errors.append(
                f"docker/Dockerfile: ARG {key} has a default; the pin belongs in docker/base-images.env only"
            )
        if key not in declared:
            declared.append(key)
    for key in declared:
        if key not in pins:
            errors.append(f"docker/Dockerfile: ARG {key} has no pin in docker/base-images.env")
    for key in pins:
        if key not in declared:
            errors.append(f"docker/base-images.env: {key} is not an ARG of docker/Dockerfile")
    return errors


def _load(pins_path: Path, dockerfile_path: Path) -> dict[str, str]:
    try:
        pins = parse_pins(pins_path.read_text(encoding="utf-8"))
    except PinError as exc:
        raise SystemExit(f"::error::docker/base-images.env {exc}") from None
    if dockerfile_path.exists():
        errors = check(pins, dockerfile_path.read_text(encoding="utf-8"))
        if errors:
            raise SystemExit("\n".join(f"::error::{e}" for e in errors))
    return pins


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--pins", type=Path, default=PINS_FILE)
    parser.add_argument("--dockerfile", type=Path, default=DOCKERFILE)
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("check", help="validate the pins (and the Dockerfile, if present)")
    sub.add_parser("build-args", help="print KEY=VALUE lines")
    sub.add_parser("cli", help="print --build-arg options for docker build")
    output = sub.add_parser("github-output", help="write KEY=VALUE lines to $GITHUB_OUTPUT")
    output.add_argument("--name", required=True)
    args = parser.parse_args(argv)

    try:
        pins = _load(args.pins, args.dockerfile)
    except SystemExit as exc:
        print(exc.code, file=sys.stderr)
        return 1

    lines = [f"{key}={value}" for key, value in pins.items()]
    if args.command == "check":
        print(f"{len(pins)} base image pins in {args.pins.name}: ok")
    elif args.command == "build-args":
        print("\n".join(lines))
    elif args.command == "cli":
        print(" ".join(f"--build-arg {line}" for line in lines))
    else:
        if not re.fullmatch(r"[a-z][a-z0-9-]*", args.name):
            print("::error::invalid output name", file=sys.stderr)
            return 1
        delimiter = f"PINS_{secrets.token_hex(16)}"
        with open(os.environ["GITHUB_OUTPUT"], "a", encoding="utf-8") as fh:
            fh.write(f"{args.name}<<{delimiter}\n" + "\n".join(lines) + f"\n{delimiter}\n")
    return 0


if __name__ == "__main__":
    sys.exit(main())
