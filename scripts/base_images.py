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

`check --base REF` (CI on a pull request: the target branch) also compares
with the pins of that ref, because the file merges without review: a pin may
not go down in version and must keep its flavour (the tag suffix after the
version, such as -php8.5-trixie or -bookworm-slim). And every pin that differs
from the base must carry exactly the digest Docker Hub serves for its tag now
(anonymous token, three attempts; an unreachable registry fails): a changed
digest under an unchanged tag is no longer a way to bring in another image.
Pins that did not change are not asked about, so a tag that moved on upstream
does not turn unrelated pull requests red. A REF that is not a commit fails
the check (the comparison is never switched off silently); a REF without the
pin file is fine, the branch adds it.

Every command checks first and fails on a malformed pin, on a pin whose image
is not on ALLOWED_REPOSITORIES (the file has no code owner, so only a tag or
digest may change without review), or on a Dockerfile that does not match: an image ARG with a default (a second pin Renovate does
not update), an image ARG without a pin, or a pin no ARG uses.
Standard library only.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import secrets
import subprocess
import sys
import time
import urllib.error
import urllib.request
from collections.abc import Callable
from pathlib import Path
from typing import Any

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
# The image behind each pin. docker/base-images.env has no code owner so that
# a tag or digest update merges after green CI; which image a key names is a
# review decision, so the allowlist lives here, in an owned file. A pull
# request that changes the repository of a pin fails `check` (CI job
# "Base image pins", a required check) until this list is changed with the
# maintainer's review.
ALLOWED_REPOSITORIES = {
    "FRANKENPHP_IMAGE": "docker.io/dunglas/frankenphp",
    "COMPOSER_IMAGE": "docker.io/library/composer",
    "NODE_IMAGE": "docker.io/library/node",
}

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


def repository(value: str) -> str:
    """The repository of a pin, normalised: registry/path, Docker Hub written out."""
    name = value.split("@", 1)[0].rsplit(":", 1)[0]
    first, _, rest = name.partition("/")
    if not rest:
        return f"docker.io/library/{name}"
    if "." not in first and ":" not in first and first != "localhost":
        name = f"docker.io/{name}"
    elif first in ("index.docker.io", "registry-1.docker.io"):
        name = f"docker.io/{rest}"
    if name.count("/") == 1 and name.startswith("docker.io/"):
        name = "docker.io/library/" + name.split("/", 1)[1]
    return name


def allowlist_errors(pins: dict[str, str]) -> list[str]:
    """Every pin whose image is not the one ALLOWED_REPOSITORIES names for its key."""
    errors: list[str] = []
    for key, value in pins.items():
        allowed = ALLOWED_REPOSITORIES.get(key)
        if allowed is None:
            errors.append(f"docker/base-images.env: {key} has no entry in the allowlist of scripts/base_images.py")
        elif repository(value) != allowed:
            errors.append(
                f"docker/base-images.env: {key} must be {allowed}, not {value.split('@', 1)[0].rsplit(':', 1)[0]} "
                "(the allowlist is in scripts/base_images.py)"
            )
    return errors


def check(pins: dict[str, str], dockerfile: str) -> list[str]:
    """The allowlist, and the differences between the pins and the image ARGs of the Dockerfile."""
    errors: list[str] = allowlist_errors(pins)
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


_TAG_RE = re.compile(r"\Av?(\d+(?:\.\d+)*)(.*)\Z")


def split_pin(value: str) -> tuple[str, str, str]:
    """(repository, tag, digest) of a pin."""
    image, _, digest = value.partition("@")
    repository, _, tag = image.rpartition(":")
    return repository, tag, digest


def tag_version(tag: str) -> tuple[tuple[int, ...], str] | None:
    """(version numbers, flavour suffix) of a tag such as 1.14.0-php8.5-trixie."""
    match = _TAG_RE.match(tag)
    if not match:
        return None
    return tuple(int(n) for n in match.group(1).split(".")), match.group(2)


def base_errors(base: dict[str, str], pins: dict[str, str]) -> list[str]:
    """Downgrades and flavour changes against the pins of the merge base."""
    errors: list[str] = []
    for key in sorted(set(base) & set(pins)):
        _, old_tag, _ = split_pin(base[key])
        _, new_tag, _ = split_pin(pins[key])
        if old_tag == new_tag:
            continue
        old, new = tag_version(old_tag), tag_version(new_tag)
        if old is None or new is None:
            errors.append(f"docker/base-images.env: {key} tag {new_tag} cannot be compared with {old_tag}")
            continue
        if new[1] != old[1]:
            errors.append(f"docker/base-images.env: {key} changes its flavour from {old_tag} to {new_tag}")
        elif new[0] < old[0]:
            errors.append(f"docker/base-images.env: {key} goes down from {old_tag} to {new_tag}")
    return errors


Fetch = Callable[[str, dict[str, str], str], Any]
_MANIFEST_TYPES = ", ".join(
    (
        "application/vnd.oci.image.index.v1+json",
        "application/vnd.docker.distribution.manifest.list.v2+json",
        "application/vnd.oci.image.manifest.v1+json",
        "application/vnd.docker.distribution.manifest.v2+json",
    )
)


def _http(url: str, headers: dict[str, str], method: str) -> Any:
    request = urllib.request.Request(url, method=method, headers={"User-Agent": "tesserarr-base-images", **headers})
    with urllib.request.urlopen(request, timeout=30) as response:  # noqa: S310 (fixed Docker Hub hosts)
        return json.load(response) if method == "GET" else response.headers.get("Docker-Content-Digest")


def _retry(fetch: Fetch, url: str, headers: dict[str, str], method: str) -> Any:
    for attempt in range(3):
        try:
            return fetch(url, headers, method)
        except (urllib.error.URLError, TimeoutError, OSError):
            if attempt == 2:
                raise
            time.sleep(2**attempt)
    return None


def hub_digest(repository: str, tag: str, fetch: Fetch = _http) -> str:
    """The digest Docker Hub serves for repository:tag (an index for multi-arch tags)."""
    path = repository.removeprefix("docker.io/")
    token = _retry(
        fetch, f"https://auth.docker.io/token?service=registry.docker.io&scope=repository:{path}:pull", {}, "GET"
    )["token"]
    digest = _retry(
        fetch,
        f"https://registry-1.docker.io/v2/{path}/manifests/{tag}",
        {"Authorization": f"Bearer {token}", "Accept": _MANIFEST_TYPES},
        "HEAD",
    )
    return str(digest or "")


def digest_errors(base: dict[str, str], pins: dict[str, str], fetch: Fetch = _http) -> list[str]:
    """Every pin that differs from the base must carry the digest Docker Hub serves for its tag."""
    errors: list[str] = []
    for key, value in pins.items():
        if base.get(key) == value:
            continue
        _, tag, digest = split_pin(value)
        full = repository(value)
        if not full.startswith("docker.io/"):
            errors.append(f"docker/base-images.env: {key} is not on Docker Hub; its digest cannot be checked")
            continue
        try:
            served = hub_digest(full, tag, fetch)
        except (urllib.error.URLError, TimeoutError, OSError, KeyError, ValueError) as exc:
            errors.append(f"docker/base-images.env: {key} could not be checked against Docker Hub ({exc})")
            continue
        if served != digest:
            errors.append(f"docker/base-images.env: {key} pins {digest}, but Docker Hub serves {served or 'nothing'} for {tag}")
    return errors


def _is_commit(pins_path: Path, ref: str) -> bool:
    """`ref` names a commit of the git repository the pin file lives in."""
    directory = pins_path.resolve().parent
    done = subprocess.run(
        ["git", "-C", str(directory), "rev-parse", "--verify", "--quiet", "--end-of-options", f"{ref}^{{commit}}"],
        capture_output=True,
        text=True,
        check=False,
    )
    return done.returncode == 0


def _git_show(pins_path: Path, ref: str) -> str | None:
    """The pin file at `ref`, from the git repository the pin file lives in."""
    directory = pins_path.resolve().parent
    top = subprocess.run(["git", "-C", str(directory), "rev-parse", "--show-toplevel"], capture_output=True, text=True, check=False)
    if top.returncode != 0:
        return None
    rel = pins_path.resolve().relative_to(Path(top.stdout.strip())).as_posix()
    done = subprocess.run(["git", "-C", str(directory), "show", f"{ref}:{rel}"], capture_output=True, text=True, check=False)
    return done.stdout if done.returncode == 0 else None


def _load(pins_path: Path, dockerfile_path: Path) -> dict[str, str]:
    try:
        pins = parse_pins(pins_path.read_text(encoding="utf-8"))
    except PinError as exc:
        raise SystemExit(f"::error::docker/base-images.env {exc}") from None
    errors = (
        check(pins, dockerfile_path.read_text(encoding="utf-8"))
        if dockerfile_path.exists()
        else allowlist_errors(pins)
    )
    if errors:
        raise SystemExit("\n".join(f"::error::{e}" for e in errors))
    return pins


def main(argv: list[str] | None = None, fetch: Fetch = _http) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--pins", type=Path, default=PINS_FILE)
    parser.add_argument("--dockerfile", type=Path, default=DOCKERFILE)
    sub = parser.add_subparsers(dest="command", required=True)
    check_cmd = sub.add_parser("check", help="validate the pins (and the Dockerfile, if present)")
    check_cmd.add_argument("--base", help="git ref of the merge base: no downgrade, same flavour, digests as served")
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
    if args.command == "check" and args.base:
        if not _is_commit(args.pins, args.base):
            print(f"::error::--base {args.base} is not a commit; the pins cannot be compared with it", file=sys.stderr)
            return 1
        text = _git_show(args.pins, args.base)
        try:
            base = parse_pins(text) if text else {}
        except PinError:
            base = {}
        errors = base_errors(base, pins) + digest_errors(base, pins, fetch)
        if errors:
            print("\n".join(f"::error::{e}" for e in errors), file=sys.stderr)
            return 1
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
