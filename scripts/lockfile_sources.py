#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Where the locked dependencies are downloaded from.

The lockfiles have no code owner (.github/CODEOWNERS), so a dependency update
that changes only them merges without review. This check keeps every locked
download on the expected hosts, so such a change can move a version but never
the place a package comes from. CI job "Lockfile sources", a required check.

    python3 scripts/lockfile_sources.py [--root DIR]

A host check alone is not enough: a changed lockfile could point an entry at
ANOTHER package on the same host, with the matching integrity. So the URL must
also name the package and version of its entry.

npm (package-lock.json, .github/release-tools/package-lock.json, lockfile
version 2 or 3): every package resolves to exactly
https://registry.npmjs.org/<name>/-/<basename>-<version>.tgz (for a scoped
package /@scope/name/-/name-<version>.tgz) with a sha512 integrity. The name
comes from the key (after the last node_modules/). An entry's `name` may
differ only for an alias that the package.json next to the lockfile (owned,
unlike the lockfile) declares as "<key name>": "npm:<name>@…"; any other
`name` is an error. Names follow the npm name grammar and versions are strict
semver; a `resolved` path with `%` or a `.` or `..` segment is refused before
anything is compared. An entry
without `resolved` must be bundled inside another package (`inBundle`) or a
link to a path inside the repository. The legacy `dependencies` tree of
version 2 is checked with the same rules.

Composer (composer.lock): `dist` is api.github.com/repos/<owner>/<repo>/zipball/<ref>,
codeload.github.com/<owner>/<repo>/<format>/<ref> or a repo.packagist.org
path that names the package; `<ref>` is the `reference` of the dist. `source`
is github.com/<owner>/<repo>[.git] with the same owner/repo as the dist and
the same reference. A `path` package must point inside the repository. Both
`packages` and `packages-dev` are checked, and together they must not be empty.
A `path` dist does not end the check: a `source` next to it is checked too.

A lockfile that does not exist on the branch is skipped; none at all fails, and
so does a lockfile without packages.
Standard library only. Values from the lockfiles are data: printed, never run.
"""

from __future__ import annotations

import argparse
import json
import sys
import re
from pathlib import Path, PurePosixPath
from typing import Any
from urllib.parse import unquote, urlsplit

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


NPM_NAME_RE = re.compile(r"\A(?:@[A-Za-z0-9][A-Za-z0-9._~-]*/)?[A-Za-z0-9][A-Za-z0-9._~-]*\Z")
SEMVER_RE = re.compile(
    r"\A(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)"
    r"(?:-[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?(?:\+[0-9A-Za-z-]+(?:\.[0-9A-Za-z-]+)*)?\Z"
)
_ALIAS_SPEC_RE = re.compile(r"\Anpm:((?:@[^/@]+/)?[^/@]+)@")
_DEPENDENCY_FIELDS = ("dependencies", "devDependencies", "optionalDependencies", "peerDependencies", "overrides")


def declared_aliases(manifest: dict[str, Any]) -> dict[str, str]:
    """`{"<key name>": "<real name>"}` for every `npm:` alias a package.json declares."""
    aliases: dict[str, str] = {}
    for field in _DEPENDENCY_FIELDS:
        for key, spec in (manifest.get(field) or {}).items():
            if isinstance(spec, str):
                match = _ALIAS_SPEC_RE.match(spec)
                if match:
                    aliases[key] = match.group(1)
    return aliases


def _npm_tarball_path(package: str, version: str) -> str:
    return f"/{package}/-/{package.rsplit('/', 1)[-1]}-{version}.tgz"


def _npm_entry(entry: dict[str, Any], package: str, key: str, name: str) -> list[str]:
    resolved = entry.get("resolved")
    version = str(entry.get("version") or "")
    if not NPM_NAME_RE.match(package):
        return [f"{name}: {key} has the package name {package!r}, which is not an npm name"]
    if not SEMVER_RE.match(version):
        return [f"{name}: {key} has the version {version!r}, which is not a semantic version"]
    if not isinstance(resolved, str) or not _https_on(resolved, NPM_HOSTS):
        return [f"{name}: {key} resolves to {resolved!r}, not https://registry.npmjs.org/"]
    parts = urlsplit(resolved)
    if "%" in parts.path or any(s in (".", "..") for s in parts.path.split("/")):
        return [f"{name}: {key} resolves to {resolved!r}, a path with escapes or dot segments"]
    expected = _npm_tarball_path(package, version)
    if parts.path != expected or parts.query or parts.fragment:
        return [f"{name}: {key} resolves to {resolved!r}, not the tarball {expected} of its own package"]
    if not str(entry.get("integrity") or "").startswith("sha512-"):
        return [f"{name}: {key} has no sha512 integrity"]
    return []


_ALIAS_RE = re.compile(r"\Anpm:((?:@[^/@]+/)?[^/@]+)@(.+)\Z")


def _legacy_errors(tree: dict[str, Any], name: str, trail: str, aliases: dict[str, str]) -> list[str]:
    """The `dependencies` tree of lockfile version 2, with the rules of `packages`."""
    errors: list[str] = []
    for package, entry in tree.items():
        if not isinstance(entry, dict):
            continue
        key = f"{trail}{package}"
        if not entry.get("bundled") and entry.get("resolved") is not None:
            version = str(entry.get("version") or "")
            alias = _ALIAS_RE.match(version)
            if alias and not (trail == "" and aliases.get(package) == alias.group(1)):
                errors.append(f"{name}: dependencies {key} is an alias that package.json does not declare")
            else:
                real, real_version = (alias.group(1), alias.group(2)) if alias else (package, version)
                errors.extend(_npm_entry({**entry, "version": real_version}, real, f"dependencies {key}", name))
        elif not entry.get("bundled"):
            errors.append(f"{name}: dependencies {key} has no resolved URL and is not bundled")
        errors.extend(_legacy_errors(entry.get("dependencies") or {}, name, f"{key} > ", aliases))
    return errors


def npm_errors(lock: dict[str, Any], name: str, manifest: dict[str, Any] | None = None) -> list[str]:
    aliases = declared_aliases(manifest or {})
    errors: list[str] = []
    if lock.get("lockfileVersion") not in (2, 3):
        return [f"{name}: lockfileVersion {lock.get('lockfileVersion')!r} is not 2 or 3"]
    packages = lock.get("packages")
    if not isinstance(packages, dict) or not [k for k in packages if k]:
        return [f"{name}: no packages"]
    legacy = lock.get("dependencies")
    if legacy is not None:
        if lock.get("lockfileVersion") != 2 or not isinstance(legacy, dict):
            errors.append(f"{name}: a legacy dependencies tree belongs to lockfile version 2 only")
        else:
            errors.extend(_legacy_errors(legacy, name, "", aliases))
    for key, entry in packages.items():
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
        package = key.rsplit("node_modules/", 1)[-1]
        declared = entry.get("name")
        if declared is not None and declared != package:
            # Only a top-level alias the package.json declares may rename.
            if key != f"node_modules/{package}" or aliases.get(package) != declared:
                errors.append(f"{name}: {key} names the package {declared!r}, an alias package.json does not declare")
                continue
            package = str(declared)
        errors.extend(_npm_entry(entry, package, key, name))
    return errors


_API_ZIPBALL = re.compile(r"\A/repos/([^/]+)/([^/]+)/zipball/([^/]+)\Z")
_CODELOAD = re.compile(r"\A/([^/]+)/([^/]+)/(?:legacy\.zip|zip|legacy\.tar\.gz|tar\.gz)/([^/]+)\Z")
_GIT_SOURCE = re.compile(r"\A/([^/]+)/([^/]+?)(?:\.git)?\Z")


def _composer_package(package: dict[str, Any], name: str) -> list[str]:
    pkg = str(package.get("name"))
    dist = package.get("dist") if isinstance(package.get("dist"), dict) else None
    source = package.get("source") if isinstance(package.get("source"), dict) else None
    if dist is None and source is None:
        return [f"{name}: {pkg} has neither dist nor source"]
    repo_of: dict[str, tuple[str, str]] = {}
    if dist is not None and dist.get("type") == "path":
        url = str(dist.get("url") or "")
        if not _inside(url):
            return [f"{name}: {pkg} dist path {url!r} lies outside the repository"]
    elif dist is not None:
        url = str(dist.get("url") or "")
        parts = urlsplit(url)
        if not _https_on(url, COMPOSER_HOSTS) or parts.query or parts.fragment:
            return [f"{name}: {pkg} dist URL {url!r} is not on an allowed https host"]
        path = unquote(parts.path)
        github = _API_ZIPBALL.match(path) if parts.hostname == "api.github.com" else None
        github = github or (_CODELOAD.match(path) if parts.hostname == "codeload.github.com" else None)
        if github:
            if github.group(3) != str(dist.get("reference") or ""):
                return [f"{name}: {pkg} dist URL {url!r} does not name its reference"]
            repo_of["dist"] = (github.group(1).lower(), github.group(2).lower())
        elif parts.hostname == "repo.packagist.org":
            segments = [s.lower() for s in path.split("/") if s]
            vendor, _, short = pkg.lower().partition("/")
            if not any(segments[i : i + 2] == [vendor, short] for i in range(len(segments) - 1)):
                return [f"{name}: {pkg} dist URL {url!r} does not name the package"]
        else:
            return [f"{name}: {pkg} dist URL {url!r} is not a GitHub archive or a Packagist file"]
    if source is not None:
        url = str(source.get("url") or "")
        parts = urlsplit(url)
        match = _GIT_SOURCE.match(unquote(parts.path)) if parts.hostname == "github.com" else None
        if not _https_on(url, frozenset({"github.com"})) or parts.query or parts.fragment or not match:
            return [f"{name}: {pkg} source URL {url!r} is not a https://github.com/<owner>/<repo> repository"]
        repo_of["source"] = (match.group(1).lower(), match.group(2).lower())
        if (
            dist is not None
            and dist.get("type") != "path"
            and str(source.get("reference") or "") != str(dist.get("reference") or "")
        ):
            return [f"{name}: {pkg} source and dist have different references"]
    if len(repo_of) == 2 and repo_of["dist"] != repo_of["source"]:
        return [f"{name}: {pkg} dist and source are different repositories"]
    return []


def composer_errors(lock: dict[str, Any], name: str) -> list[str]:
    errors: list[str] = []
    count = 0
    for section in ("packages", "packages-dev"):
        packages = lock.get(section, [])
        if not isinstance(packages, list):
            errors.append(f"{name}: {section} is not a list")
            continue
        for package in packages:
            if not isinstance(package, dict):
                errors.append(f"{name}: an entry of {section} is not an object")
                continue
            count += 1
            errors.extend(_composer_package(package, name))
    if not count and not errors:
        errors.append(f"{name}: no packages")
    return errors


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--root", type=Path, default=ROOT)
    args = parser.parse_args(argv)

    errors: list[str] = []
    checked: list[str] = []
    for files, npm in ((NPM_LOCKFILES, True), (COMPOSER_LOCKFILES, False)):
        for rel in files:
            path = args.root / rel
            if not path.exists():
                continue
            try:
                lock = json.loads(path.read_text(encoding="utf-8"))
                manifest_path = path.parent / "package.json"
                manifest = json.loads(manifest_path.read_text(encoding="utf-8")) if npm and manifest_path.exists() else {}
            except json.JSONDecodeError as exc:
                errors.append(f"{rel}: not JSON ({exc})")
                continue
            checked.append(rel)
            errors.extend(npm_errors(lock, rel, manifest) if npm else composer_errors(lock, rel))
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
