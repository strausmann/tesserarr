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
without `resolved` must be bundled inside another package, whose tarball and
integrity are checked. Bundled is derived from the structure, not from the
`inBundle` flag (a field of the lock): an ancestor P whose bundleDependencies
names the package, or the dependency closure of those inside P/node_modules/.
The flag must agree with that, and a bundled entry has no `resolved`. The legacy `dependencies` tree of
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

A Composer package sits in `packages` exactly when `require` reaches it, and
in `packages-dev` when only `require-dev` does (`composer install --no-dev`
and the licence gate read `packages` only).

With --base <ref> (CI on a pull request: the target branch), versions are
compared with the lockfile of that ref, unless the manifest next to the
lockfile changed too (then the code owner reviews it): per package name and
major, the highest version may not go down and no new copy may sit below it,
and no major appears that the base did not hold; for npm also per consumer,
the version each dependency resolves to by Node's lookup may not go down. A
prerelease ranks below its release (13.43.0-RC1 < 13.43.0). Renovate only
raises versions and never merges a major by itself. Bundled copies (inBundle)
are no allowed versions: they ship inside their parent's tarball. A consumer
moved to a new place in the tree may not load less than the copies of the
same package loaded in the base (consumer_errors). A --base that is not a
commit fails the check instead of switching the comparison off. Composer
prereleases are ordered like composer/semver (beta9 < beta10).

Known false alarms, all failing closed (CONTRIBUTING.md, "Dependency updates"):
an npm alias that only a dependency (not package.json) declares, a Composer
metapackage without dist or source, a dev-* version whose reference moved on
Packagist, a path repository declared with a glob, and an npm dedupe that
lets a package load an older copy already in the tree (within its range, but
below what it loaded on the base).

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


def _bundlers(packages: dict[str, Any]) -> dict[str, str]:
    """Bundled entry -> the package whose tarball ships it, derived from the structure.

    `inBundle` is a field of the lock and so as untrusted as the rest of it.
    What counts is the structure: a package P whose (registry-compared)
    `bundleDependencies` names a dependency ships it inside P/node_modules/,
    and with it every dependency of a bundled package that resolves inside
    P/node_modules/ too (npm bundles the closure: in the lock of the npm
    package only 65 of its 143 bundled entries are named in
    bundleDependencies). `true` bundles all dependencies.
    """
    packages = {k: v for k, v in packages.items() if k and isinstance(v, dict)}
    owner: dict[str, str] = {}
    for parent, entry in sorted(packages.items()):
        names = entry.get("bundleDependencies", entry.get("bundledDependencies"))
        if names is True:
            names = list(entry.get("dependencies") or {})
        if not isinstance(names, list):
            continue
        prefix = f"{parent}/node_modules/"
        queue = [(parent, n) for n in names if isinstance(n, str)]
        while queue:
            location, dependency = queue.pop()
            target = _resolve(packages, location, dependency)
            if target and target.startswith(prefix) and target not in owner:
                owner[target] = parent
                queue.extend((target, d) for d in _edges(packages[target], False))
    return owner


def bundled_keys(packages: dict[str, Any]) -> set[str]:
    """The entries the structure shows as bundled (see _bundlers); never the flag."""
    return set(_bundlers(packages))


def bundle_errors(packages: dict[str, Any], name: str) -> list[str]:
    """`inBundle` must say exactly what the structure says, and a bundled entry has no download of its own."""
    owner = _bundlers(packages)
    errors: list[str] = []
    for key, entry in sorted(packages.items()):
        if not key or not isinstance(entry, dict):
            continue
        if key in owner:
            if entry.get("resolved") is not None:
                errors.append(f"{name}: {key} is bundled but has a resolved URL")
            if not entry.get("inBundle"):
                errors.append(f"{name}: {key} is bundled by {owner[key]} but not marked inBundle")
        elif entry.get("inBundle"):
            errors.append(f"{name}: {key} is marked inBundle, but no package above it bundles it")
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
    errors.extend(bundle_errors(packages, name))
    bundled = bundled_keys(packages)
    for key, entry in packages.items():
        if key == "" or not isinstance(entry, dict):
            continue
        resolved = entry.get("resolved")
        if entry.get("link"):
            errors.append(f"{name}: {key} is a link; this project has no workspaces")
            continue
        if resolved is None:
            if key not in bundled:
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
    for key in bundled_keys(packages):
        if any(key.startswith(f"{r}/node_modules/") for r in reached):
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
    bundled = bundled_keys(packages)
    for key in sorted(production):
        entry = packages[key]
        if key in bundled:
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


# Text that does not change what Composer downloads or installs. `license` is
# not one of them: the licence gate reads it from the lock (composer licenses
# --locked), so it is compared like everything else. Packagist
# writes some of them differently (keyword order, empty lists left out), so
# they are not compared. Everything else is.
DESCRIPTIVE_FIELDS = frozenset(
    {"authors", "description", "funding", "homepage", "keywords", "support", "time"}
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


def _composer_reach(by_name: dict[str, dict[str, Any]], roots: list[str]) -> set[str]:
    """Names reached from `roots` through `require`; replace/provide only of reached packages."""
    reached: set[str] = set()
    pending = [r.lower() for r in roots]
    unmet: set[str] = set()
    while True:
        while pending:
            wanted = pending.pop()
            if PLATFORM_RE.match(wanted) or wanted in reached:
                continue
            if wanted in by_name:
                reached.add(wanted)
                pending.extend(r.lower() for r in (by_name[wanted].get("require") or {}))
            else:
                unmet.add(wanted)
        met = {
            wanted
            for wanted in unmet
            for owner in reached
            if wanted
            in {k.lower() for k in (by_name[owner].get("replace") or {})}
            | {k.lower() for k in (by_name[owner].get("provide") or {})}
        }
        if not met:
            return reached
        unmet -= met


def composer_orphan_errors(lock: dict[str, Any], name: str, manifest: dict[str, Any]) -> list[str]:
    """Packages composer.json does not reach, and packages in the wrong section.

    The `require`, `replace` and `provide` of each entry are compared with
    Packagist (packagist_errors); the roots come from composer.json (owned).
    A requirement that no package carries by name counts as met by `replace`
    or `provide` only of a package that is reached on its own, so an added
    package cannot stand in for a real one. A package belongs in `packages`
    exactly when `require` reaches it (`composer install --no-dev` installs
    that section, and the licence gate reads it with --no-dev); one that only
    `require-dev` reaches belongs in `packages-dev`.
    """
    sections = {
        section: [p for p in lock.get(section) or [] if isinstance(p, dict)] for section in ("packages", "packages-dev")
    }
    by_name = {str(p.get("name")).lower(): p for packages in sections.values() for p in packages}
    runtime = _composer_reach(by_name, list(manifest.get("require") or {}))
    everything = _composer_reach(by_name, list(manifest.get("require") or {}) + list(manifest.get("require-dev") or {}))
    errors: list[str] = []
    for section, packages in sections.items():
        for package in packages:
            pkg = str(package.get("name")).lower()
            if pkg not in everything:
                errors.append(f"{name}: {package.get('name')} is not required by composer.json or by any package it reaches")
            elif section == "packages" and pkg not in runtime:
                errors.append(f"{name}: {package.get('name')} is only required for development but listed in packages")
            elif section == "packages-dev" and pkg in runtime:
                errors.append(f"{name}: {package.get('name')} is required at runtime but listed in packages-dev")
    return errors


_VERSION_RE = re.compile(r"\Av?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?(?:-([0-9A-Za-z.-]+))?(?:\+.*)?\Z")
# composer/semver VersionParser::$modifierRegex (src/VersionParser.php, 3.x):
# [._-]?(?:(stable|beta|b|RC|alpha|a|patch|pl|p)((?:[.-]?\d+)*+)?)?([.-]?dev)?
_COMPOSER_VERSION_RE = re.compile(
    r"\Av?(\d+)(?:\.(\d+))?(?:\.(\d+))?(?:\.(\d+))?"
    r"(?:[._-]?(?:(stable|beta|b|rc|alpha|a|patch|pl|p)((?:[.-]?\d+)*))?([.-]?dev)?)?(?:\+.*)?\Z",
    re.IGNORECASE,
)
# VersionParser::expandStability maps a/b/p/pl/rc to alpha/beta/patch/RC; the
# normalised versions are then ordered by PHP's version_compare(), which ranks
# dev < alpha < beta < RC < (a release) < patch and compares the numbers that
# follow numerically (php.net/version_compare): so beta9 < beta10.
_COMPOSER_STAGE = {"alpha": 1, "a": 1, "beta": 2, "b": 2, "rc": 3, "stable": 4, "patch": 5, "pl": 5, "p": 5}


def _composer_numeric(version: str) -> tuple[Any, ...] | None:
    match = _COMPOSER_VERSION_RE.match(version)
    if not match:
        return None  # Composer's normalize() refuses such a version too
    numbers = tuple(int(g or 0) for g in match.groups()[:4])
    stage, digits, dev = match.group(5), match.group(6), match.group(7)
    if stage is None:
        # "1.0.0-dev" alone is below every alpha; a plain release is stable.
        return (*numbers, 0 if dev else 4, (), 1)
    counters = tuple(int(n) for n in re.findall(r"\d+", digits or ""))
    # A trailing -dev (beta10-dev) sits below the version it leads to.
    return (*numbers, _COMPOSER_STAGE[stage.lower()], counters, 0 if dev else 1)


def _numeric(version: str, composer: bool = False) -> tuple[Any, ...] | None:
    """A sortable key: the numbers, then a release above every prerelease of the same numbers.

    npm, SemVer precedence: 13.43.0-RC1 < 13.43.0; prerelease identifiers
    compare numerically where both are numbers, else as text (numbers first),
    so beta10 < beta9 exactly as npm sorts them. Composer instead splits a
    suffix into stage and number like composer/semver (_composer_numeric).
    """
    if composer:
        return _composer_numeric(version)
    match = _VERSION_RE.match(version)
    if not match:
        return None
    numbers = tuple(int(g or 0) for g in match.groups()[:4])
    pre = match.group(5)
    if pre is None:
        return (*numbers, 1, ())
    parts = tuple((0, int(x), "") if x.isdigit() else (1, 0, x.lower()) for x in pre.split("."))
    return (*numbers, 0, parts)


def _locked_versions(lock: dict[str, Any], npm: bool) -> dict[str, set[str]]:
    """Package name -> every version the lockfile holds of it (npm may hold several)."""
    found: dict[str, set[str]] = {}
    if npm:
        packages = lock.get("packages") or {}
        bundled = bundled_keys(packages)
        for key, entry in packages.items():
            # A bundled copy ships inside its parent's tarball (checked there);
            # it is no version anybody may pick, and moves with its parent.
            # Bundled by the structure (bundled_keys), not by the inBundle flag.
            if key and isinstance(entry, dict) and key not in bundled:
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
                numbers = _numeric(version, composer=not npm)
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


def consumer_errors(base: dict[str, Any], head: dict[str, Any], name: str, manifest: dict[str, Any]) -> list[str]:
    """npm: per consumer, the version its dependency resolves to may not go down.

    For every package that exists at the same place in base and head (and for
    package.json itself), each dependency is resolved by Node's lookup in both
    trees; a lower version in head is refused. This closes what the per-name
    comparison cannot see: an older copy that exists somewhere in the base
    (for instance bundled inside another package) placed under a consumer
    that used to load a newer one.

    A consumer at a place the base does not have (moved in the tree) is
    compared with the base resolutions of the consumers of the same package
    name: what it loads may not be below the LOWEST of them. Lowest, not
    highest, because copies of one package may legitimately load different
    versions of a dependency (each within its own range), and a moved copy
    that keeps loading what one of them loaded is no downgrade. The base
    copies of the same version are taken first, as they declare the same
    ranges (compared with the registry); only for a version the base does
    not hold are all copies of that name taken. Bundled consumers and
    bundled targets of the base do not count: they ship inside another
    package's tarball, and their versions are nobody's to pick. Bundled
    means bundled by the structure (bundled_keys), never by the inBundle
    flag, which the lock could set anywhere.
    """
    old = {k: v for k, v in (base.get("packages") or {}).items() if k and isinstance(v, dict)}
    new = {k: v for k, v in (head.get("packages") or {}).items() if k and isinstance(v, dict)}

    def package_of(key: str, entry: dict[str, Any]) -> str:
        return str(entry.get("name") or key.rsplit("node_modules/", 1)[-1])

    consumers: list[tuple[str, list[str]]] = [
        ("", [d for kind in ("dependencies", "devDependencies", "optionalDependencies", "peerDependencies") for d in (manifest.get(kind) or {})])
    ]
    old_bundled, new_bundled = bundled_keys(old), bundled_keys(new)
    consumers += [(key, _edges(new[key], False)) for key in sorted(set(old) & set(new)) if key not in new_bundled]
    errors: list[str] = []
    for location, dependencies in consumers:
        for dependency in sorted(set(dependencies)):
            before, after = _resolve(old, location, dependency), _resolve(new, location, dependency)
            if before is None or after is None:
                continue
            was, now = str(old[before].get("version")), str(new[after].get("version"))
            was_key, now_key = _numeric(was), _numeric(now)
            if was_key is not None and now_key is not None and now_key < was_key:
                errors.append(f"{name}: {location or 'package.json'} now loads {dependency} {now} instead of {was}")

    base_copies: dict[str, list[tuple[str, str]]] = {}
    for key, entry in old.items():
        if key not in old_bundled:
            base_copies.setdefault(package_of(key, entry), []).append((key, str(entry.get("version"))))
    for key in sorted(set(new) - set(old)):
        entry = new[key]
        consumer = package_of(key, entry)
        if key in new_bundled or consumer not in base_copies:
            continue
        version = str(entry.get("version"))
        copies = [k for k, v in base_copies[consumer] if v == version] or [k for k, _ in base_copies[consumer]]
        for dependency in sorted(set(_edges(entry, False))):
            target = _resolve(new, key, dependency)
            now_key = _numeric(str(new[target].get("version"))) if target else None
            loaded: list[tuple[tuple[Any, ...], str]] = []
            for copy in copies:
                before = _resolve(old, copy, dependency)
                if before is not None and before not in old_bundled:
                    was = str(old[before].get("version"))
                    was_key = _numeric(was)
                    if was_key is not None:
                        loaded.append((was_key, was))
            if target is None or now_key is None or not loaded:
                continue
            lowest_key, lowest = min(loaded)
            if now_key < lowest_key:
                errors.append(
                    f"{name}: {key} now loads {dependency} {new[target].get('version')}, below {lowest} that {consumer} loads in the base"
                )
    return errors


def _git_show(root: Path, ref: str, rel: str) -> str | None:
    import subprocess

    done = subprocess.run(["git", "-C", str(root), "show", f"{ref}:{rel}"], capture_output=True, text=True, check=False)
    return done.stdout if done.returncode == 0 else None


def _is_commit(root: Path, ref: str) -> bool:
    """`ref` names a commit of the repository at `root` (a missing file at that ref is fine)."""
    import subprocess

    done = subprocess.run(
        ["git", "-C", str(root), "rev-parse", "--verify", "--quiet", "--end-of-options", f"{ref}^{{commit}}"],
        capture_output=True,
        text=True,
        check=False,
    )
    return done.returncode == 0


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

    if args.base and not _is_commit(args.root, args.base):
        # A base that does not resolve would switch the comparison off silently.
        print(f"::error::--base {args.base} is not a commit in {args.root}; the versions cannot be compared", file=sys.stderr)
        return 1
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
                        base_lock = json.loads(old_lock)
                        errors.extend(version_errors(base_lock, lock, rel, npm))
                        if npm:
                            errors.extend(consumer_errors(base_lock, lock, rel, manifest))
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
