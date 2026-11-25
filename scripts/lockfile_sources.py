#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Where the locked dependencies are downloaded from.

The lockfiles have no code owner (.github/CODEOWNERS), so a dependency update
that changes only them merges without review. This check keeps every locked
download on the expected hosts, so such a change can move a version but never
the place a package comes from. CI job "Lockfile sources", a required check.

    python3 scripts/lockfile_sources.py [--root DIR]

npm (package-lock.json, .github/release-tools/package-lock.json): every
package resolves to https://registry.npmjs.org/ with a sha512 integrity; an
entry without `resolved` must be bundled inside another package (`inBundle`)
or a link to a path inside the repository.

Composer (composer.lock): every `dist` and `source` URL is https on
github.com, api.github.com, codeload.github.com or repo.packagist.org; a
`path` package must point inside the repository.

A lockfile that does not exist on the branch is skipped; none at all fails.
Standard library only. Values from the lockfiles are data: printed, never run.
"""

from __future__ import annotations

import argparse
import json
import sys
from pathlib import Path, PurePosixPath
from typing import Any
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parent.parent
NPM_LOCKFILES = ("package-lock.json", ".github/release-tools/package-lock.json")
COMPOSER_LOCKFILES = ("composer.lock",)
NPM_HOSTS = frozenset({"registry.npmjs.org"})
COMPOSER_HOSTS = frozenset({"github.com", "api.github.com", "codeload.github.com", "repo.packagist.org"})


def _https_on(url: str, hosts: frozenset[str]) -> bool:
    try:
        parts = urlsplit(url)
        port = parts.port
    except ValueError:
        return False
    return (
        parts.scheme == "https"
        and parts.username is None
        and parts.password is None
        and port in (None, 443)
        and parts.hostname in hosts
    )


def _inside(path: str) -> bool:
    pure = PurePosixPath(path)
    return bool(path) and not pure.is_absolute() and ".." not in pure.parts and ":" not in path


def npm_errors(lock: dict[str, Any], name: str) -> list[str]:
    errors: list[str] = []
    for key, entry in (lock.get("packages") or {}).items():
        if key == "" or not isinstance(entry, dict):
            continue
        resolved = entry.get("resolved")
        if entry.get("link"):
            if not isinstance(resolved, str) or not _inside(resolved):
                errors.append(f"{name}: {key} links outside the repository")
            continue
        if resolved is None:
            if not entry.get("inBundle"):
                errors.append(f"{name}: {key} has no resolved URL and is not bundled")
            continue
        if not isinstance(resolved, str) or not _https_on(resolved, NPM_HOSTS):
            errors.append(f"{name}: {key} resolves to {resolved!r}, not https://registry.npmjs.org/")
            continue
        if not str(entry.get("integrity") or "").startswith("sha512-"):
            errors.append(f"{name}: {key} has no sha512 integrity")
    return errors


def composer_errors(lock: dict[str, Any], name: str) -> list[str]:
    errors: list[str] = []
    for section in ("packages", "packages-dev"):
        for package in lock.get(section) or []:
            if not isinstance(package, dict):
                continue
            pkg = str(package.get("name"))
            places = [(k, package[k]) for k in ("dist", "source") if isinstance(package.get(k), dict)]
            if not places:
                errors.append(f"{name}: {pkg} has neither dist nor source")
            for kind, place in places:
                url = str(place.get("url") or "")
                if place.get("type") == "path":
                    if not _inside(url):
                        errors.append(f"{name}: {pkg} {kind} path {url!r} lies outside the repository")
                elif not _https_on(url, COMPOSER_HOSTS):
                    errors.append(f"{name}: {pkg} {kind} URL {url!r} is not on an allowed https host")
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args(argv)

    errors: list[str] = []
    checked: list[str] = []
    for files, check in ((NPM_LOCKFILES, npm_errors), (COMPOSER_LOCKFILES, composer_errors)):
        for rel in files:
            path = args.root / rel
            if not path.exists():
                continue
            try:
                lock = json.loads(path.read_text(encoding="utf-8"))
            except json.JSONDecodeError as exc:
                errors.append(f"{rel}: not JSON ({exc})")
                continue
            checked.append(rel)
            errors.extend(check(lock, rel))
    if not checked and not errors:
        print("::error::no lockfile found", file=sys.stderr)
        return 1
    for error in errors:
        print(f"::error::{error}", file=sys.stderr)
    if errors:
        return 1
    print("lockfile sources ok: " + ", ".join(checked))
    return 0


if __name__ == "__main__":
    sys.exit(main())
