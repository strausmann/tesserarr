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
package /@scope/name/-/name-<version>.tgz) with a sha512 integrity. A
`link` entry is refused (the project has no workspaces; a link could put
another installed package under a trusted name). The name
comes from the key (after the last node_modules/). An entry's `name` may
differ only for an alias that the package.json next to the lockfile (owned,
unlike the lockfile) declares as "<key name>": "npm:<name>@…"; any other
`name` is an error. Names follow the npm name grammar and versions are strict
semver; a `resolved` path with `%` or a `.` or `..` segment is refused before
anything is compared. An entry
without `resolved` must be bundled inside another package (`inBundle`), whose
tarball and integrity are checked. The legacy `dependencies` tree of
version 2 is checked with the same rules.

A matching tarball is not enough either: npm creates the `.bin` links and
decides about install scripts from the lock entry, not from the tarball. So
every registry entry is compared with its published version document
(https://registry.npmjs.org/<name>/<version>, fetched in parallel with
retries; an unreachable registry fails the check): `bin` (a string normalised
to {name: path}, a leading ./ removed), `dependencies`,
`optionalDependencies`, and `peerDependencies`, `peerDependenciesMeta`,
`engines`, `os`, `cpu`, `libc`, `license`, `funding`, `deprecated`,
`bundleDependencies` and `workspaces` where the lock has them, and
`hasInstallScript` against the preinstall, install and postinstall scripts and
`gypfile`. A key outside NPM_ENTRY_FIELDS is refused. Every entry must also be
reachable from the package.json next to the lockfile (owned) by Node's
resolution (node_modules/a/node_modules/b, then the parent directories): a
package nobody requires is an orphan and an error, so the lockfile cannot add
a package whose only purpose is a `.bin` name. The edges of that walk are the
dependency fields compared with the registry, so the lockfile cannot invent
one; it also derives which packages the runtime needs, which must not be
flagged `dev` (`npm ci --omit=dev` would drop them, also from the licence
gate) or, when required, `optional`.

Composer (composer.lock): GitHub URLs alone prove nothing about which package
they hold, so every package that is not a `path` package is compared with
its published metadata on Packagist (https://repo.packagist.org/p2/<name>.json,
`~dev.json` for dev versions, the minified composer/2.0 format expanded):
the locked version must exist there, and every field of the locked entry
except the descriptive ones (DESCRIPTIVE_FIELDS: description, keywords,
authors, ...) must equal the published one exactly: the whole `dist` and
`source` objects (so no `mirrors`), `bin`, `autoload`, `extra`, `type`,
`require` and any key Packagist does not have (so no `transport-options`). A
Packagist that cannot be reached fails the check; it never passes unverified. Besides that, `dist` is api.github.com/repos/<owner>/<repo>/zipball/<ref>,
codeload.github.com/<owner>/<repo>/<format>/<ref> or a repo.packagist.org
path that names the package; `<ref>` is the `reference` of the dist. `source`
is github.com/<owner>/<repo>[.git] with the same owner/repo as the dist and
the same reference. A `path` package must point inside the repository. Both
`packages` and `packages-dev` are checked, and together they must not be empty.
A `path` package is accepted only when composer.json (owned, unlike the
lock) declares a `path` repository with exactly that URL, the directory
exists in the repository and its composer.json carries the package's name;
it has no `source`. Any other `path` dist is refused, so a lockfile alone
cannot turn a published package into an unchecked local one.

Composer packages must be reachable as well: from `require` and `require-dev`
of composer.json through the `require` of reached packages (compared with
Packagist); a `replace` or `provide` meets a requirement only for a package
that is reached by its own name. Platform requirements (php, ext-*, lib-*,
composer-*) are skipped. `notification-url` is absent or Packagist's own.

With --base <ref> (CI on a pull request: the target branch), every version is
compared with the lockfile of that ref: going down or changing the major
fails, unless the manifest next to the lockfile changed too (then the code
owner reviews it). Renovate only raises versions and never merges a major by
itself.

Known false alarms, all failing closed (CONTRIBUTING.md, "Dependency updates"):
an npm alias that only a dependency (not package.json) declares, a Composer
metapackage without dist or source, a dev-* version whose reference moved on
Packagist, and a path repository declared with a glob.

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
import urllib.error
import urllib.request
import time
from collections.abc import Callable
from concurrent.futures import ThreadPoolExecutor
from typing import Any
from urllib.parse import quote, unquote, urlsplit

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
            errors.append(f"{name}: {key} is a link; this project has no workspaces")
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


def declared_path_repositories(manifest: dict[str, Any]) -> set[str]:
    """URLs of the `path` repositories composer.json declares."""
    repositories = manifest.get("repositories") or []
    if isinstance(repositories, dict):
        repositories = list(repositories.values())
    return {
        str(r.get("url"))
        for r in repositories
        if isinstance(r, dict) and r.get("type") == "path" and isinstance(r.get("url"), str)
    }


def _path_package_errors(package: dict[str, Any], name: str, root: Path | None, declared: set[str]) -> list[str]:
    pkg = str(package.get("name"))
    url = str((package.get("dist") or {}).get("url") or "")
    if package.get("source") is not None:
        return [f"{name}: {pkg} is a path package with a source"]
    if not _inside(url) or url not in declared:
        return [f"{name}: {pkg} dist path {url!r} is not a path repository declared in composer.json"]
    if root is None:
        return [f"{name}: {pkg} dist path {url!r} cannot be checked without the repository"]
    try:
        local = json.loads((root / url / "composer.json").read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return [f"{name}: {pkg} dist path {url!r} holds no composer.json"]
    if local.get("name") != pkg:
        return [f"{name}: {pkg} dist path {url!r} holds the package {local.get('name')!r}"]
    return []


def _composer_package(package: dict[str, Any], name: str) -> list[str]:
    pkg = str(package.get("name"))
    dist = package.get("dist") if isinstance(package.get("dist"), dict) else None
    source = package.get("source") if isinstance(package.get("source"), dict) else None
    if dist is None and source is None:
        return [f"{name}: {pkg} has neither dist nor source"]
    repo_of: dict[str, tuple[str, str]] = {}
    if dist is not None and dist.get("type") == "path":
        return []  # composer_errors checks path packages against composer.json
    if dist is not None:
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


NPM_REGISTRY = "https://registry.npmjs.org/"
# Lock entry keys: bookkeeping npm derives itself (dev, optional, devOptional,
# peer, inBundle, the name of an alias), the download (version, resolved,
# integrity) and published metadata, which is compared with the registry.
NPM_BOOKKEEPING_FIELDS = frozenset(
    {"version", "resolved", "integrity", "dev", "optional", "devOptional", "peer", "inBundle", "name"}
)
NPM_COMPARED_FIELDS = (
    "bin", "dependencies", "optionalDependencies", "peerDependencies", "peerDependenciesMeta",
    "engines", "os", "cpu", "libc", "license", "funding", "deprecated", "bundleDependencies", "workspaces",
)  # fmt: skip
# Fields compared even when the lock leaves them out: their absence changes
# what npm links or installs.
NPM_ALWAYS_COMPARED = frozenset({"bin", "dependencies", "optionalDependencies", "peerDependencies"})
NPM_ENTRY_FIELDS = NPM_BOOKKEEPING_FIELDS | frozenset(NPM_COMPARED_FIELDS) | {"hasInstallScript"}
_INSTALL_SCRIPTS = ("preinstall", "install", "postinstall")
_DEPENDENCY_KINDS = ("dependencies", "optionalDependencies", "peerDependencies")


def _normal_bin(name: str, value: Any) -> Any:
    if isinstance(value, str):
        value = {name.rsplit("/", 1)[-1]: value}
    if isinstance(value, dict):
        return {k: v[2:] if isinstance(v, str) and v.startswith("./") else v for k, v in value.items()}
    return value


def _normal_funding(value: Any) -> Any:
    """npm writes a funding string as {"url": ...}, also inside a list."""
    if isinstance(value, str):
        return {"url": value}
    if isinstance(value, list):
        return [_normal_funding(v) for v in value]
    return value


def _published_field(doc: dict[str, Any], field: str, package: str) -> Any:
    if field == "bin":
        return _normal_bin(package, doc.get("bin")) if doc.get("bin") else None
    if field == "funding":
        return _normal_funding(doc.get("funding"))
    if field == "dependencies":
        # An optional dependency is not repeated in dependencies (npm package.json docs,
        # "optionalDependencies": entries there override those in dependencies).
        optional = doc.get("optionalDependencies") or {}
        return {k: v for k, v in (doc.get("dependencies") or {}).items() if k not in optional}
    if field == "bundleDependencies":
        return doc.get("bundleDependencies", doc.get("bundledDependencies"))
    if field == "license" and isinstance(doc.get("license"), dict):
        return doc["license"].get("type")
    return doc.get(field)


def _empty(value: Any) -> bool:
    return value is None or value == {} or value == []


def npm_metadata_errors(lock: dict[str, Any], name: str, fetch: Fetch | None = None, workers: int = 16) -> list[str]:
    """Every registry entry of the lock compared with its published version document."""
    fetch = fetch or fetch_json
    wanted: dict[tuple[str, str], list[tuple[str, dict[str, Any]]]] = {}
    errors: list[str] = []
    for key, entry in (lock.get("packages") or {}).items():
        if key == "" or not isinstance(entry, dict) or entry.get("link"):
            continue
        unknown = sorted(set(entry) - NPM_ENTRY_FIELDS)
        if unknown:
            errors.append(f"{name}: {key} has keys npm metadata does not explain: {', '.join(unknown)}")
            continue
        package = str(entry.get("name") or key.rsplit("node_modules/", 1)[-1])
        wanted.setdefault((package, str(entry.get("version") or "")), []).append((key, entry))

    def get(item: tuple[str, str]) -> tuple[tuple[str, str], Any]:
        package, version = item
        url = f"{NPM_REGISTRY}{quote(package, safe='@')}/{quote(version, safe='')}"
        last: Exception | None = None
        for attempt in range(3):
            try:
                return item, fetch(url)
            except urllib.error.HTTPError as exc:
                if exc.code == 404:
                    return item, None
                last = exc
            except (urllib.error.URLError, TimeoutError, ValueError, OSError) as exc:
                last = exc
            if attempt < 2:
                time.sleep(attempt + 1)
        return item, last

    with ThreadPoolExecutor(max_workers=workers) as pool:
        results = dict(pool.map(get, sorted(wanted)))
    for (package, version), entries in sorted(wanted.items()):
        doc = results[(package, version)]
        for key, entry in entries:
            if isinstance(doc, Exception):
                errors.append(f"{name}: {key} could not be compared with the npm registry ({doc})")
                continue
            if not isinstance(doc, dict) or doc.get("version") != version:
                errors.append(f"{name}: {key} {package}@{version} is not published on the npm registry")
                continue
            for field in NPM_COMPARED_FIELDS:
                if field not in entry and field not in NPM_ALWAYS_COMPARED:
                    continue
                mine = entry.get(field)
                if field == "bin":
                    mine = _normal_bin(package, mine)
                elif field == "funding":
                    mine = _normal_funding(mine)
                theirs = _published_field(doc, field, package)
                if _empty(mine) and _empty(theirs):
                    continue
                if mine != theirs:
                    errors.append(f"{name}: {key} {field} differs from {package}@{version} on the npm registry")
            scripts = doc.get("scripts") if isinstance(doc.get("scripts"), dict) else {}
            installs = any(scripts.get(s) for s in _INSTALL_SCRIPTS) or bool(doc.get("gypfile"))
            if bool(entry.get("hasInstallScript")) != installs:
                errors.append(f"{name}: {key} hasInstallScript differs from {package}@{version} on the npm registry")
    return errors


def _resolve(packages: dict[str, Any], location: str, dependency: str) -> str | None:
    """Node's lookup: <location>/node_modules/<dep>, then each parent directory."""
    base = location
    while True:
        candidate = f"{base}/node_modules/{dependency}" if base else f"node_modules/{dependency}"
        if candidate in packages:
            return candidate
        if not base:
            return None
        cut = base.rfind("/node_modules/")
        base = base[:cut] if cut >= 0 else ""


def _edges(entry: dict[str, Any], required_only: bool) -> list[str]:
    """Dependency names of an entry; with required_only, without optional ones."""
    names = list(entry.get("dependencies") or {})
    meta = entry.get("peerDependenciesMeta") or {}
    for peer in entry.get("peerDependencies") or {}:
        if not (required_only and isinstance(meta.get(peer), dict) and meta[peer].get("optional")):
            names.append(peer)
    if not required_only:
        names += list(entry.get("optionalDependencies") or {})
    return names


def _reach(packages: dict[str, Any], roots: list[str], required_only: bool) -> set[str]:
    reached: set[str] = set()
    queue: list[tuple[str, list[str]]] = [("", roots)]
    while queue:
        location, names = queue.pop()
        for dependency in names:
            target = _resolve(packages, location, dependency)
            if target and target not in reached:
                reached.add(target)
                queue.append((target, _edges(packages[target], required_only)))
    # Bundled packages ship inside the tarball of a reached parent.
    for key, entry in packages.items():
        if entry.get("inBundle") and any(key.startswith(f"{r}/node_modules/") for r in reached):
            reached.add(key)
    return reached


def orphan_errors(lock: dict[str, Any], name: str, manifest: dict[str, Any]) -> list[str]:
    """Entries no dependency of package.json reaches, and dev/optional flags that hide runtime packages.

    The edges are the dependency fields of the entries, which npm_metadata_errors
    compares with the registry; the roots come from package.json (owned).
    """
    packages = {k: v for k, v in (lock.get("packages") or {}).items() if k and isinstance(v, dict)}
    runtime = list(manifest.get("dependencies") or {}) + list(manifest.get("peerDependencies") or {})
    optional = list(manifest.get("optionalDependencies") or {})
    everything = _reach(packages, runtime + optional + list(manifest.get("devDependencies") or {}), False)
    production = _reach(packages, runtime + optional, False)
    required = _reach(packages, runtime, True)
    errors = [
        f"{name}: {key} is not required by package.json or by any package it reaches"
        for key in sorted(packages)
        if key not in everything
    ]
    for key in sorted(production):
        entry = packages[key]
        if entry.get("inBundle"):
            continue  # part of its parent's tarball, flagged by npm from the parent's view
        # `npm ci --omit=dev` (licence gate, image) drops dev; omit=optional drops optional.
        if entry.get("dev"):
            errors.append(f"{name}: {key} is needed at runtime but marked dev")
        if key in required and (entry.get("optional") or entry.get("devOptional")):
            errors.append(f"{name}: {key} is required at runtime but marked optional")
    return errors


PACKAGIST = "https://repo.packagist.org/p2/"
COMPOSER_NAME_RE = re.compile(r"\A[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9](([_.]|-{1,2})?[a-z0-9]+)*\Z")
Fetch = Callable[[str], Any]


def fetch_json(url: str) -> Any:
    request = urllib.request.Request(url, headers={"User-Agent": "tesserarr-lockfile-sources"})
    with urllib.request.urlopen(request, timeout=30) as response:  # noqa: S310 (fixed https host)
        return json.load(response)


def expand_minified(versions: list[dict[str, Any]]) -> list[dict[str, Any]]:
    """composer/2.0 minified metadata: each version repeats only what changed."""
    expanded: list[dict[str, Any]] = []
    current: dict[str, Any] = {}
    for entry in versions:
        current = dict(current)
        for key, value in entry.items():
            if value == "__unset":
                current.pop(key, None)
            else:
                current[key] = value
        expanded.append(current)
    return expanded


def _with_retry(fetch: Fetch, url: str, attempts: int = 3) -> Any:
    """Up to three attempts with backoff; a 404 is final at once (None)."""
    for attempt in range(attempts):
        try:
            return fetch(url)
        except urllib.error.HTTPError as exc:
            if exc.code == 404:
                return None
            if attempt == attempts - 1:
                raise
        except (urllib.error.URLError, TimeoutError, OSError):
            if attempt == attempts - 1:
                raise
        time.sleep(2**attempt)
    return None


def _published(pkg: str, version: str, fetch: Fetch, cache: dict[str, list[dict[str, Any]]]) -> dict[str, Any] | None:
    url = f"{PACKAGIST}{quote(pkg)}{'~dev' if version.startswith('dev-') or version.endswith('-dev') else ''}.json"
    if url not in cache:
        data = _with_retry(fetch, url)
        versions = ((data or {}).get("packages") or {}).get(pkg) or []
        cache[url] = expand_minified(versions) if (data or {}).get("minified") == "composer/2.0" else list(versions)
    return next((v for v in cache[url] if v.get("version") == version), None)


# Text that does not change what Composer downloads or installs. Packagist
# writes some of them differently (keyword order, empty lists left out), so
# they are not compared. Everything else is.
DESCRIPTIVE_FIELDS = frozenset(
    {"authors", "description", "funding", "homepage", "keywords", "license", "support", "time"}
)
# Composer posts the installed package list to notification-url; only
# Packagist's own address is accepted (or none).
NOTIFICATION_URL = "https://packagist.org/downloads/"
PLATFORM_RE = re.compile(r"\A(?:php(?:-64bit|-ipv6|-zts|-debug)?|hhvm|ext-.+|lib-.+|composer(?:-plugin-api|-runtime-api)?)\Z")
PACKAGIST_ONLY_FIELDS = frozenset({"published-time", "version_normalized"})


def packagist_errors(lock: dict[str, Any], name: str, fetch: Fetch = fetch_json) -> list[str]:
    """Every non-path package exactly as Packagist publishes it, but for descriptive text."""
    errors: list[str] = []
    cache: dict[str, list[dict[str, Any]]] = {}
    for section in ("packages", "packages-dev"):
        for package in lock.get(section) or []:
            if not isinstance(package, dict):
                continue
            dist = package.get("dist") if isinstance(package.get("dist"), dict) else {}
            if dist.get("type") == "path":
                continue
            pkg, version = str(package.get("name")), str(package.get("version") or "")
            if not COMPOSER_NAME_RE.match(pkg):
                errors.append(f"{name}: {pkg!r} is not a Composer package name")
                continue
            try:
                published = _published(pkg, version, fetch, cache)
            except (urllib.error.URLError, TimeoutError, ValueError, OSError) as exc:
                errors.append(f"{name}: {pkg} could not be compared with Packagist ({exc})")
                continue
            if published is None:
                errors.append(f"{name}: {pkg} {version} is not published on Packagist")
                continue
            if package.get("notification-url", NOTIFICATION_URL) != NOTIFICATION_URL:
                errors.append(f"{name}: {pkg} {version} notification-url is not {NOTIFICATION_URL}")
            for field in sorted(
                (set(package) | set(published)) - DESCRIPTIVE_FIELDS - PACKAGIST_ONLY_FIELDS - {"notification-url"}
            ):
                if package.get(field) != published.get(field):
                    errors.append(f"{name}: {pkg} {version} {field} differs from Packagist")
    return errors


def composer_orphan_errors(lock: dict[str, Any], name: str, manifest: dict[str, Any]) -> list[str]:
    """Packages that composer.json does not reach through the `require` of reached packages.

    The `require`, `replace` and `provide` of each entry are compared with
    Packagist (packagist_errors); the roots come from composer.json (owned).
    A requirement that no package carries by name counts as met by `replace`
    or `provide` only of a package that is reached on its own, so an added
    package cannot stand in for a real one.
    """
    packages = [p for section in ("packages", "packages-dev") for p in lock.get(section) or [] if isinstance(p, dict)]
    by_name = {str(p.get("name")).lower(): p for p in packages}
    roots = list((manifest.get("require") or {})) + list((manifest.get("require-dev") or {}))
    reached: set[str] = set()
    pending = [r.lower() for r in roots]
    unmet: set[str] = set()
    while pending or unmet:
        progress = False
        while pending:
            wanted = pending.pop()
            if PLATFORM_RE.match(wanted) or wanted in reached:
                continue
            if wanted in by_name:
                reached.add(wanted)
                pending.extend(r.lower() for r in (by_name[wanted].get("require") or {}))
                progress = True
            else:
                unmet.add(wanted)
        for wanted in sorted(unmet):
            for owner in sorted(reached):
                package = by_name[owner]
                if wanted in {k.lower() for k in (package.get("replace") or {})} | {
                    k.lower() for k in (package.get("provide") or {})
                }:
                    unmet.discard(wanted)
                    progress = True
                    break
        if not progress:
            break
    return [
        f"{name}: {p.get('name')} is not required by composer.json or by any package it reaches"
        for p in packages
        if str(p.get("name")).lower() not in reached
    ]


_VERSION_RE = re.compile(r"\Av?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:[-+].*)?\Z")


def _numeric(version: str) -> tuple[int, ...] | None:
    match = _VERSION_RE.match(version)
    return tuple(int(g or 0) for g in match.groups()) if match else None


def _locked_versions(lock: dict[str, Any], npm: bool) -> dict[str, set[str]]:
    """Package name -> every version the lockfile holds of it (npm may hold several)."""
    found: dict[str, set[str]] = {}
    if npm:
        for key, entry in (lock.get("packages") or {}).items():
            if key and isinstance(entry, dict):
                package = str(entry.get("name") or key.rsplit("node_modules/", 1)[-1])
                found.setdefault(package, set()).add(str(entry.get("version")))
        return found
    for section in ("packages", "packages-dev"):
        for package in lock.get(section) or []:
            if isinstance(package, dict):
                found.setdefault(str(package.get("name")).lower(), set()).add(str(package.get("version")))
    return found


def version_errors(base: dict[str, Any], head: dict[str, Any], name: str, npm: bool) -> list[str]:
    """Downgrades and new majors against the lockfile of the merge base, per package name and major.

    Compared by name, not by location: a package moved elsewhere in the
    node_modules tree is still the same package. For every major the base
    holds, the highest head version of that major is at least the highest
    base version, and every version new in that major is at least that high
    (so dropping the higher of two copies is a downgrade). A major may vanish
    only for a higher major the base already held; a major the base did not
    hold is refused (Renovate only raises versions and never merges a major by
    itself). dev and other non-numeric versions are left to the other checks.
    """
    errors: list[str] = []
    old, new = _locked_versions(base, npm), _locked_versions(head, npm)
    for package in sorted(set(old) & set(new)):
        before: dict[int, dict[str, tuple[int, ...]]] = {}
        after: dict[int, dict[str, tuple[int, ...]]] = {}
        for versions, groups in ((old[package], before), (new[package], after)):
            for version in versions:
                numbers = _numeric(version)
                if numbers is not None:
                    groups.setdefault(numbers[0], {})[version] = numbers
        if not before:
            continue
        for major in sorted(set(after) - set(before)):
            errors.append(f"{name}: {package} {', '.join(sorted(after[major]))} is a major version the base did not hold")
        for major, base_versions in sorted(before.items()):
            highest = max(base_versions.values())
            shown = max(base_versions, key=lambda v: base_versions[v])
            head_versions = after.get(major)
            if not head_versions:
                if not any(m > major for m in after if m in before):
                    errors.append(f"{name}: {package} {major}.x is gone without a higher major of the base")
                continue
            if max(head_versions.values()) < highest:
                errors.append(f"{name}: {package} {major}.x goes down below {shown}, the highest of the base")
                continue
            for version, numbers in sorted(head_versions.items()):
                if version not in base_versions and numbers < highest:
                    errors.append(f"{name}: {package} {version} is new but below {shown}, the highest {major}.x of the base")
    return errors


def _git_show(root: Path, ref: str, rel: str) -> str | None:
    import subprocess

    done = subprocess.run(["git", "-C", str(root), "show", f"{ref}:{rel}"], capture_output=True, text=True, check=False)
    return done.stdout if done.returncode == 0 else None


def composer_errors(
    lock: dict[str, Any], name: str, manifest: dict[str, Any] | None = None, root: Path | None = None
) -> list[str]:
    errors: list[str] = []
    declared = declared_path_repositories(manifest or {})
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
            if isinstance(package.get("dist"), dict) and package["dist"].get("type") == "path":
                errors.extend(_path_package_errors(package, name, root, declared))
            else:
                errors.extend(_composer_package(package, name))
    if not count and not errors:
        errors.append(f"{name}: no packages")
    return errors


def main(argv: list[str] | None = None, fetch: Fetch = fetch_json) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("--root", type=Path, default=ROOT)
    parser.add_argument(
        "--base",
        help="git ref of the merge base (e.g. origin/main): refuse downgrades and major changes against its lockfiles",
    )
    parser.add_argument(
        "--offline",
        action="store_true",
        help="skip the comparison with Packagist and the npm registry (self-tests only; CI runs without it)",
    )
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
                manifest_path = path.parent / ("package.json" if npm else "composer.json")
                manifest = json.loads(manifest_path.read_text(encoding="utf-8")) if manifest_path.exists() else {}
            except json.JSONDecodeError as exc:
                errors.append(f"{rel}: not JSON ({exc})")
                continue
            checked.append(rel)
            if args.base:
                old_lock = _git_show(args.root, args.base, rel)
                manifest_rel = str(Path(rel).parent / ("package.json" if npm else "composer.json"))
                old_manifest = _git_show(args.root, args.base, manifest_rel)
                current_manifest = manifest_path.read_text(encoding="utf-8") if manifest_path.exists() else None
                if old_lock is not None and old_manifest == current_manifest:
                    try:
                        errors.extend(version_errors(json.loads(old_lock), lock, rel, npm))
                    except json.JSONDecodeError:
                        errors.append(f"{rel}: the lockfile of {args.base} is not JSON")
                elif old_lock is not None:
                    print(f"{rel}: the manifest changed since {args.base}; versions are left to the review")
            if npm:
                found = npm_errors(lock, rel, manifest)
                if not found:
                    found = orphan_errors(lock, rel, manifest)
                errors.extend(found)
                if not found and not args.offline:
                    errors.extend(npm_metadata_errors(lock, rel, fetch))
            else:
                found = composer_errors(lock, rel, manifest, path.parent)
                if not found:
                    found = composer_orphan_errors(lock, rel, manifest)
                errors.extend(found)
                if not found and not args.offline:
                    errors.extend(packagist_errors(lock, rel, fetch))
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
