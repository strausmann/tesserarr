# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/lockfile_sources.py.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import io
import json
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

import lockfile_sources as ls

ROOT = Path(__file__).resolve().parent.parent
SHA = "sha512-" + "A" * 86 + "=="


def npm_lock(**packages: dict[str, Any]) -> dict[str, Any]:
    return {"lockfileVersion": 3, "packages": {"": {"name": "root"}, **packages}}


REF = "4da2233e72eeecd9be3b62e0dc2cc9ed8e2e31c2"


def npm_pkg(url: str = "https://registry.npmjs.org/a/-/a-1.0.0.tgz", integrity: str = SHA) -> dict[str, Any]:
    entry: dict[str, Any] = {"version": "1.0.0", "resolved": url}
    if integrity:
        entry["integrity"] = integrity
    return entry


def composer_pkg(
    dist: str = f"https://api.github.com/repos/A/B/zipball/{REF}",
    source: str = "https://github.com/A/B.git",
    dist_ref: str = REF,
    source_ref: str = REF,
) -> dict[str, Any]:
    return {
        "name": "a/b",
        "dist": {"type": "zip", "url": dist, "reference": dist_ref},
        "source": {"type": "git", "url": source, "reference": source_ref},
    }


class NpmTest(unittest.TestCase):
    def test_registry_packages_with_sha512_pass(self) -> None:
        self.assertEqual(ls.npm_errors(npm_lock(**{"node_modules/a": npm_pkg()}), "package-lock.json"), [])

    def test_a_bundled_entry_needs_no_resolved(self) -> None:
        lock = npm_lock(**{"node_modules/a/node_modules/b": {"version": "1", "inBundle": True}})
        self.assertEqual(ls.npm_errors(lock, "package-lock.json"), [])

    def test_a_scoped_package_and_an_alias_resolve_to_their_own_tarballs(self) -> None:
        lock = npm_lock(
            **{
                "node_modules/@s/p": npm_pkg("https://registry.npmjs.org/@s/p/-/p-1.0.0.tgz"),
                "node_modules/vite": {**npm_pkg("https://registry.npmjs.org/@v/core/-/core-1.0.0.tgz"), "name": "@v/core"},
            }
        )
        manifest = {"devDependencies": {"vite": "npm:@v/core@1.0.0"}}
        self.assertEqual(ls.npm_errors(lock, "x", manifest), [])

    def test_a_name_that_package_json_does_not_declare_as_an_alias_is_refused(self) -> None:
        # The lockfile alone could rename any entry and point it at another package.
        forged = {**npm_pkg("https://registry.npmjs.org/evil/-/evil-1.0.0.tgz"), "name": "evil"}
        cases = {
            "no manifest": (npm_lock(**{"node_modules/a": forged}), {}),
            "alias for another package": (npm_lock(**{"node_modules/a": forged}), {"dependencies": {"a": "npm:other@1"}}),
            "nested key": (npm_lock(**{"node_modules/x/node_modules/a": forged}), {"dependencies": {"a": "npm:evil@1"}}),
        }
        for case, (lock, manifest) in cases.items():
            with self.subTest(case=case):
                errors = ls.npm_errors(lock, "x", manifest)
                self.assertEqual(len(errors), 1)
                self.assertIn("alias package.json does not declare", errors[0])

    def test_escapes_dot_segments_and_bad_versions_or_names_are_refused_first(self) -> None:
        # Each case must be stopped by its own rule, before the path comparison
        # (which would catch most of them as well); the message tells which.
        cases = {
            "%2e%2e": (npm_pkg("https://registry.npmjs.org/x/%2e%2e/a/-/a-1.0.0.tgz"), "node_modules/a", "dot segments"),
            "dot-dot segment": (npm_pkg("https://registry.npmjs.org/x/../a/-/a-1.0.0.tgz"), "node_modules/a", "dot segments"),
            "dot segment": (npm_pkg("https://registry.npmjs.org/./a/-/a-1.0.0.tgz"), "node_modules/a", "dot segments"),
            "version with a slash": ({**npm_pkg(), "version": "1.0.0/../../evil"}, "node_modules/a", "not a semantic version"),
            "version with a percent": ({**npm_pkg(), "version": "1.0.0%2f"}, "node_modules/a", "not a semantic version"),
            "name with a percent": (npm_pkg(), "node_modules/a%2fb", "not an npm name"),
        }
        for case, (entry, key, reason) in cases.items():
            with self.subTest(case=case):
                errors = ls.npm_errors(npm_lock(**{key: entry}), "x")
                self.assertEqual(len(errors), 1)
                self.assertIn(reason, errors[0])

    def test_another_package_or_version_on_the_registry_is_refused(self) -> None:
        cases = {
            "other package": "https://registry.npmjs.org/evil/-/evil-1.0.0.tgz",
            "other version": "https://registry.npmjs.org/a/-/a-0.9.0.tgz",
            "other scope": "https://registry.npmjs.org/@evil/a/-/a-1.0.0.tgz",
            "query": "https://registry.npmjs.org/a/-/a-1.0.0.tgz?x=1",
            "encoded slash": "https://registry.npmjs.org/evil%2F..%2Fa/-/a-1.0.0.tgz",
        }
        for name, url in cases.items():
            with self.subTest(case=name):
                errors = ls.npm_errors(npm_lock(**{"node_modules/a": npm_pkg(url)}), "x")
                self.assertEqual(len(errors), 1)

    def test_only_lockfile_versions_2_and_3_with_packages_are_read(self) -> None:
        valid_but_v1 = {**npm_lock(**{"node_modules/a": npm_pkg()}), "lockfileVersion": 1}
        for lock in (valid_but_v1, {"lockfileVersion": 3}, npm_lock()):
            with self.subTest(lock=lock):
                self.assertEqual(len(ls.npm_errors(lock, "x")), 1)

    def test_the_legacy_tree_of_version_2_is_checked_too(self) -> None:
        lock = {**npm_lock(**{"node_modules/a": npm_pkg()}), "lockfileVersion": 2}
        lock["dependencies"] = {"a": {"version": "1.0.0", "resolved": "https://registry.npmjs.org/a/-/a-1.0.0.tgz", "integrity": SHA}}
        self.assertEqual(ls.npm_errors(lock, "x"), [])
        lock["dependencies"]["a"]["dependencies"] = {"b": {"version": "2.0.0", "resolved": "https://evil.example/b.tgz", "integrity": SHA}}
        errors = ls.npm_errors(lock, "x")
        self.assertEqual(len(errors), 1)
        self.assertIn("dependencies a > b", errors[0])

    def test_a_legacy_tree_in_version_3_is_refused(self) -> None:
        lock = npm_lock(**{"node_modules/a": npm_pkg()})
        lock["dependencies"] = {}
        self.assertEqual(len(ls.npm_errors(lock, "x")), 1)

    def test_a_foreign_resolved_url_is_refused(self) -> None:
        cases = {
            "other host": "https://evil.example/a/-/a-1.0.0.tgz",
            "http": "http://registry.npmjs.org/a/-/a-1.0.0.tgz",
            "lookalike host": "https://registry.npmjs.org.evil.example/a.tgz",
            "user info": "https://registry.npmjs.org@evil.invalid/a.tgz",
            "git": "git+ssh://git@github.com/a/b.git",
            "file": "file:../a.tgz",
        }
        for name, url in cases.items():
            with self.subTest(case=name):
                errors = ls.npm_errors(npm_lock(**{"node_modules/a": npm_pkg(url)}), "package-lock.json")
                self.assertEqual(len(errors), 1)
                self.assertIn("node_modules/a", errors[0])

    def test_a_missing_or_weak_integrity_is_refused(self) -> None:
        for integrity in ("", "sha1-abc"):
            with self.subTest(integrity=integrity):
                errors = ls.npm_errors(npm_lock(**{"node_modules/a": npm_pkg(integrity=integrity)}), "x")
                self.assertEqual(len(errors), 1)
                self.assertIn("integrity", errors[0])

    def test_an_entry_without_resolved_that_is_not_bundled_is_refused(self) -> None:
        errors = ls.npm_errors(npm_lock(**{"node_modules/a": {"version": "1"}}), "x")
        self.assertEqual(len(errors), 1)

    def test_every_link_is_refused(self) -> None:
        # A link could put another installed package under a trusted name:
        # node_modules/semantic-release -> node_modules/attacker.
        for target in ("node_modules/attacker", "packages/c", "../elsewhere"):
            with self.subTest(target=target):
                lock = npm_lock(**{"node_modules/semantic-release": {"link": True, "resolved": target}})
                errors = ls.npm_errors(lock, "x")
                self.assertEqual(len(errors), 1)
                self.assertIn("is a link", errors[0])


class ComposerTest(unittest.TestCase):
    def lock(self, *packages: dict[str, Any], dev: tuple[dict[str, Any], ...] = ()) -> dict[str, Any]:
        return {"packages": list(packages), "packages-dev": list(dev)}

    def test_github_and_packagist_pass(self) -> None:
        packagist = composer_pkg(dist="https://repo.packagist.org/p2/a/b/b-1.0.zip")
        del packagist["source"]
        lock = self.lock(
            composer_pkg(),
            composer_pkg(dist=f"https://codeload.github.com/a/b/legacy.zip/{REF}"),
            packagist,
        )
        self.assertEqual(ls.composer_errors(lock, "composer.lock"), [])

    def test_a_package_without_source_passes_on_its_dist(self) -> None:
        pkg = composer_pkg()
        del pkg["source"]
        self.assertEqual(ls.composer_errors(self.lock(dev=(pkg,)), "composer.lock"), [])

    def test_a_foreign_dist_or_source_url_is_refused(self) -> None:
        cases = {
            "dist host": composer_pkg(dist="https://evil.example/a.zip"),
            "source host": composer_pkg(source="https://gitlab.example/a/b.git"),
            "http dist": composer_pkg(dist="http://api.github.com/repos/a/b/zipball/abc"),
            "lookalike": composer_pkg(dist="https://api.github.com.evil.example/a.zip"),
        }
        for name, pkg in cases.items():
            with self.subTest(case=name):
                errors = ls.composer_errors(self.lock(pkg), "composer.lock")
                self.assertEqual(len(errors), 1)
                self.assertIn("a/b", errors[0])


    def test_dist_and_source_must_be_the_same_repository_and_reference(self) -> None:
        cases = {
            "other repository": composer_pkg(dist=f"https://api.github.com/repos/evil/B/zipball/{REF}"),
            "other source": composer_pkg(source="https://github.com/evil/B.git"),
            "zipball of another ref": composer_pkg(dist="https://api.github.com/repos/A/B/zipball/" + "f" * 40),
            "source at another ref": composer_pkg(source_ref="f" * 40),
            "packagist path of another package": composer_pkg(dist="https://repo.packagist.org/p2/evil/x.zip"),
        }
        for name, pkg in cases.items():
            with self.subTest(case=name):
                self.assertEqual(len(ls.composer_errors(self.lock(pkg), "composer.lock")), 1)


    def path_setup(self, tmp: str, declared: bool = True, local_name: str = "x/y") -> tuple[Path, dict[str, Any]]:
        root = Path(tmp)
        (root / "packages" / "y").mkdir(parents=True)
        (root / "packages" / "y" / "composer.json").write_text(json.dumps({"name": local_name}))
        manifest = {"repositories": [{"type": "path", "url": "packages/y"}]} if declared else {}
        return root, manifest

    def test_a_declared_path_package_with_its_own_name_passes(self) -> None:
        local = {"name": "x/y", "dist": {"type": "path", "url": "packages/y"}}
        with tempfile.TemporaryDirectory() as tmp:
            root, manifest = self.path_setup(tmp)
            self.assertEqual(ls.composer_errors(self.lock(local), "composer.lock", manifest, root), [])

    def test_a_published_package_turned_into_a_path_package_is_refused(self) -> None:
        # Codex: laravel/framework with dist {"type": "path", "url": "nonexistent"} and a foreign source.
        cases = {
            "undeclared path": ({"name": "x/y", "dist": {"type": "path", "url": "nonexistent-package-path"}}, True, "x/y"),
            "path not in composer.json": ({"name": "x/y", "dist": {"type": "path", "url": "packages/y"}}, False, "x/y"),
            "another package in the directory": ({"name": "laravel/framework", "dist": {"type": "path", "url": "packages/y"}}, True, "x/y"),
            "path with a source": (
                {"name": "x/y", "dist": {"type": "path", "url": "packages/y"}, "source": {"type": "git", "url": "https://github.com/evil/x.git", "reference": REF}},
                True,
                "x/y",
            ),
            "outside the repository": ({"name": "x/y", "dist": {"type": "path", "url": "../../etc"}}, True, "x/y"),
        }
        for case, (pkg, declared, local_name) in cases.items():
            with self.subTest(case=case), tempfile.TemporaryDirectory() as tmp:
                root, manifest = self.path_setup(tmp, declared, local_name)
                self.assertEqual(len(ls.composer_errors(self.lock(pkg), "composer.lock", manifest, root)), 1)

    def test_a_lock_without_packages_is_refused(self) -> None:
        self.assertEqual(len(ls.composer_errors(self.lock(), "composer.lock")), 1)
        self.assertEqual(len(ls.composer_errors({"packages": {}}, "composer.lock")), 1)

    def test_a_package_without_dist_and_source_is_refused(self) -> None:
        self.assertEqual(len(ls.composer_errors(self.lock({"name": "a/b"}), "composer.lock")), 1)


class PackagistTest(unittest.TestCase):
    PKG = {
        "name": "a/b",
        "version": "v1.2.0",
        "dist": {"type": "zip", "url": f"https://api.github.com/repos/A/B/zipball/{REF}", "reference": REF},
        "source": {"type": "git", "url": "https://github.com/A/B.git", "reference": REF},
    }

    def metadata(self, **override: Any) -> dict[str, Any]:
        # Minified: the second version inherits everything but `version` and the references.
        newer = {"name": "a/b", "version": "v1.3.0", "dist": {**self.PKG["dist"], "reference": "f" * 40}, "source": {**self.PKG["source"], "reference": "f" * 40}}
        return {"minified": "composer/2.0", "packages": {"a/b": [newer, {"version": "v1.2.0", **{k: v for k, v in self.PKG.items() if k in ("dist", "source")}, **override}]}}

    def fetch_from(self, data: dict[str, Any]) -> Any:
        urls: list[str] = []

        def fetch(url: str) -> Any:
            urls.append(url)
            return data

        fetch.urls = urls  # type: ignore[attr-defined]
        return fetch

    def test_the_published_version_passes_and_metadata_is_fetched_once(self) -> None:
        fetch = self.fetch_from(self.metadata())
        lock = {"packages": [self.PKG], "packages-dev": [self.PKG]}
        self.assertEqual(ls.packagist_errors(lock, "composer.lock", fetch), [])
        self.assertEqual(fetch.urls, ["https://repo.packagist.org/p2/a/b.json"])

    def test_another_repository_with_matching_references_is_refused(self) -> None:
        # Both fields changed together, as Codex showed for laravel/framework.
        forged = {**self.PKG, "dist": {**self.PKG["dist"], "url": f"https://api.github.com/repos/evil/B/zipball/{REF}"}, "source": {**self.PKG["source"], "url": "https://github.com/evil/B.git"}}
        self.assertEqual(ls.composer_errors({"packages": [forged]}, "composer.lock"), [])
        errors = ls.packagist_errors({"packages": [forged]}, "composer.lock", self.fetch_from(self.metadata()))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 dist differs from Packagist", "composer.lock: a/b v1.2.0 source differs from Packagist"])

    def test_a_reference_that_packagist_does_not_publish_is_refused(self) -> None:
        forged = {**self.PKG, "dist": {**self.PKG["dist"], "reference": "e" * 40}}
        errors = ls.packagist_errors({"packages": [forged]}, "composer.lock", self.fetch_from(self.metadata()))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 dist differs from Packagist"])

    def test_a_mirror_in_dist_is_refused(self) -> None:
        forged = {**self.PKG, "dist": {**self.PKG["dist"], "mirrors": [{"url": "https://evil.invalid/%package%.zip", "preferred": True}]}}
        errors = ls.packagist_errors({"packages": [forged]}, "composer.lock", self.fetch_from(self.metadata()))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 dist differs from Packagist"])

    def test_transport_options_on_a_published_package_are_refused(self) -> None:
        forged = {**self.PKG, "transport-options": {"http": {"proxy": "tcp://evil.invalid:8080"}}}
        errors = ls.packagist_errors({"packages": [forged]}, "composer.lock", self.fetch_from(self.metadata()))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 transport-options differs from Packagist"])

    def test_install_fields_must_match_but_descriptive_text_may_differ(self) -> None:
        published = self.metadata(bin=["bin/b"], keywords=["x", "y"])
        same = {**self.PKG, "bin": ["bin/b"], "keywords": ["y"], "description": "other words"}
        self.assertEqual(ls.packagist_errors({"packages": [same]}, "composer.lock", self.fetch_from(published)), [])
        forged = {**same, "autoload": {"files": ["vendor/evil/x.php"]}}
        errors = ls.packagist_errors({"packages": [forged]}, "composer.lock", self.fetch_from(published))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 autoload differs from Packagist"])

    def test_a_licence_that_packagist_does_not_publish_is_refused(self) -> None:
        # The licence gate reads licences from the lock (composer licenses --locked).
        errors = ls.packagist_errors({"packages": [{**self.PKG, "license": ["MIT"]}]}, "composer.lock", self.fetch_from(self.metadata(license=["GPL-2.0-only"])))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 license differs from Packagist"])

    def test_a_foreign_notification_url_is_refused(self) -> None:
        forged = {**self.PKG, "notification-url": "https://evil.invalid/collect"}
        errors = ls.packagist_errors({"packages": [forged]}, "composer.lock", self.fetch_from(self.metadata()))
        self.assertEqual(errors, ["composer.lock: a/b v1.2.0 notification-url is not https://packagist.org/downloads/"])
        fine = {**self.PKG, "notification-url": "https://packagist.org/downloads/"}
        self.assertEqual(ls.packagist_errors({"packages": [fine]}, "composer.lock", self.fetch_from(self.metadata())), [])

    def test_packagist_is_retried_and_a_lasting_failure_fails(self) -> None:
        sleep = ls.time.sleep
        ls.time.sleep = lambda _: None
        try:
            calls: list[str] = []

            def flaky(url: str) -> Any:
                calls.append(url)
                if len(calls) < 2:
                    raise ls.urllib.error.URLError("blip")
                return self.metadata()

            self.assertEqual(ls.packagist_errors({"packages": [self.PKG]}, "composer.lock", flaky), [])
            self.assertEqual(len(calls), 2)

            def down(url: str) -> Any:
                calls.append(url)
                raise ls.urllib.error.URLError("down")

            calls.clear()
            self.assertEqual(len(ls.packagist_errors({"packages": [self.PKG]}, "composer.lock", down)), 1)
            self.assertEqual(len(calls), 3)
        finally:
            ls.time.sleep = sleep

    def test_an_unpublished_version_is_refused(self) -> None:
        errors = ls.packagist_errors({"packages": [{**self.PKG, "version": "v9.9.9"}]}, "composer.lock", self.fetch_from(self.metadata()))
        self.assertEqual(errors, ["composer.lock: a/b v9.9.9 is not published on Packagist"])

    def test_dev_versions_come_from_the_dev_file(self) -> None:
        fetch = self.fetch_from({"packages": {"a/b": [{**self.PKG, "version": "dev-main"}]}})
        ls.packagist_errors({"packages": [{**self.PKG, "version": "dev-main"}]}, "composer.lock", fetch)
        self.assertEqual(fetch.urls, ["https://repo.packagist.org/p2/a/b~dev.json"])

    def test_an_unreachable_packagist_fails_closed(self) -> None:
        def down(url: str) -> Any:
            raise ls.urllib.error.URLError("unreachable")

        sleep = ls.time.sleep
        ls.time.sleep = lambda _: None
        try:
            errors = ls.packagist_errors({"packages": [self.PKG]}, "composer.lock", down)
        finally:
            ls.time.sleep = sleep
        self.assertEqual(len(errors), 1)
        self.assertIn("could not be compared with Packagist", errors[0])

    def test_path_packages_are_not_looked_up(self) -> None:
        def never(url: str) -> Any:
            raise AssertionError(url)

        local = {"name": "x/y", "version": "1.0", "dist": {"type": "path", "url": "packages/y"}}
        self.assertEqual(ls.packagist_errors({"packages": [local]}, "composer.lock", never), [])

    def test_unset_markers_remove_inherited_keys(self) -> None:
        expanded = ls.expand_minified([{"version": "2", "a": 1, "b": 2}, {"version": "1", "b": "__unset"}])
        self.assertEqual(expanded, [{"version": "2", "a": 1, "b": 2}, {"version": "1", "a": 1}])


def registry_doc(name: str = "a", version: str = "1.0.0", **fields: Any) -> dict[str, Any]:
    return {"name": name, "version": version, **fields}


class NpmMetadataTest(unittest.TestCase):
    def setUp(self) -> None:
        self.sleep = ls.time.sleep
        ls.time.sleep = lambda _: None

    def tearDown(self) -> None:
        ls.time.sleep = self.sleep

    def check(self, entry: dict[str, Any], doc: dict[str, Any] | Exception | None, key: str = "node_modules/a") -> list[str]:
        def fetch(url: str) -> Any:
            if isinstance(doc, Exception):
                raise doc
            return doc

        return ls.npm_metadata_errors(npm_lock(**{key: entry}), "x", fetch)

    def test_an_entry_as_published_passes_with_npm_normalisation(self) -> None:
        entry = {**npm_pkg(), "bin": {"a": "cli.js"}, "funding": {"url": "https://example.com/f"}, "dependencies": {"b": "^1"}, "optionalDependencies": {"c": "^1"}}
        doc = registry_doc(bin="./cli.js", funding="https://example.com/f", dependencies={"b": "^1", "c": "^1"}, optionalDependencies={"c": "^1"})
        self.assertEqual(self.check(entry, doc), [])

    def test_a_removed_bin_is_refused(self) -> None:
        errors = self.check(npm_pkg(), registry_doc(bin={"a": "cli.js"}))
        self.assertEqual(errors, ["x: node_modules/a bin differs from a@1.0.0 on the npm registry"])

    def test_a_bin_the_package_does_not_publish_is_refused(self) -> None:
        errors = self.check({**npm_pkg(), "bin": {"semantic-release": "index.js"}}, registry_doc())
        self.assertEqual(errors, ["x: node_modules/a bin differs from a@1.0.0 on the npm registry"])

    def test_has_install_script_must_match_the_published_scripts(self) -> None:
        for doc in (registry_doc(scripts={"postinstall": "node x.js"}), registry_doc(gypfile=True)):
            with self.subTest(doc=doc):
                self.assertEqual(self.check(npm_pkg(), doc), ["x: node_modules/a hasInstallScript differs from a@1.0.0 on the npm registry"])
        self.assertEqual(len(self.check({**npm_pkg(), "hasInstallScript": True}, registry_doc())), 1)

    def test_an_added_dependency_is_refused(self) -> None:
        errors = self.check({**npm_pkg(), "dependencies": {"b": "^1", "evil": "1.0.0"}}, registry_doc(dependencies={"b": "^1"}))
        self.assertEqual(errors, ["x: node_modules/a dependencies differs from a@1.0.0 on the npm registry"])

    def test_a_key_npm_metadata_does_not_explain_is_refused(self) -> None:
        errors = self.check({**npm_pkg(), "scripts": {"x": "y"}}, registry_doc())
        self.assertEqual(len(errors), 1)
        self.assertIn("has keys npm metadata does not explain: scripts", errors[0])

    def test_an_unpublished_version_and_an_unreachable_registry_are_refused(self) -> None:
        self.assertIn("is not published", self.check(npm_pkg(), None)[0])
        self.assertIn("could not be compared", self.check(npm_pkg(), ls.urllib.error.URLError("down"))[0])

    def test_a_transient_failure_is_retried(self) -> None:
        calls: list[str] = []

        def flaky(url: str) -> Any:
            calls.append(url)
            if len(calls) < 3:
                raise ls.urllib.error.URLError("blip")
            return registry_doc()

        self.assertEqual(ls.npm_metadata_errors(npm_lock(**{"node_modules/a": npm_pkg()}), "x", flaky), [])
        self.assertEqual(len(calls), 3)


class NpmGraphTest(unittest.TestCase):
    def test_every_entry_reached_from_package_json_passes(self) -> None:
        lock = npm_lock(**{"node_modules/a": {**npm_pkg(), "dependencies": {"b": "^1"}}, "node_modules/b": {**npm_pkg("https://registry.npmjs.org/b/-/b-1.0.0.tgz")}})
        self.assertEqual(ls.orphan_errors(lock, "x", {"dependencies": {"a": "^1"}}), [])

    def test_the_codex_bin_scenario_is_an_orphan(self) -> None:
        # semantic-release without its bin, plus a package nobody requires that claims the bin name.
        lock = npm_lock(
            **{
                "node_modules/semantic-release": npm_pkg("https://registry.npmjs.org/semantic-release/-/semantic-release-1.0.0.tgz"),
                "node_modules/left-pad": {**npm_pkg("https://registry.npmjs.org/left-pad/-/left-pad-1.0.0.tgz"), "bin": {"semantic-release": "index.js"}},
            }
        )
        errors = ls.orphan_errors(lock, "x", {"dependencies": {"semantic-release": "1.0.0"}})
        self.assertEqual(errors, ["x: node_modules/left-pad is not required by package.json or by any package it reaches"])

    def test_node_resolution_prefers_the_nested_copy(self) -> None:
        lock = npm_lock(**{"node_modules/a": {**npm_pkg(), "dependencies": {"b": "^2"}}, "node_modules/a/node_modules/b": npm_pkg("https://registry.npmjs.org/b/-/b-1.0.0.tgz"), "node_modules/b": npm_pkg("https://registry.npmjs.org/b/-/b-1.0.0.tgz")})
        self.assertEqual(ls.orphan_errors(lock, "x", {"dependencies": {"a": "^1"}}), ["x: node_modules/b is not required by package.json or by any package it reaches"])

    def test_a_runtime_package_marked_dev_or_optional_is_refused(self) -> None:
        lock = npm_lock(**{"node_modules/a": {**npm_pkg(), "dev": True}})
        self.assertEqual(ls.orphan_errors(lock, "x", {"dependencies": {"a": "^1"}}), ["x: node_modules/a is needed at runtime but marked dev"])
        lock = npm_lock(**{"node_modules/a": {**npm_pkg(), "optional": True}})
        self.assertEqual(ls.orphan_errors(lock, "x", {"dependencies": {"a": "^1"}}), ["x: node_modules/a is required at runtime but marked optional"])
        lock = npm_lock(**{"node_modules/a": {**npm_pkg(), "dev": True}})
        self.assertEqual(ls.orphan_errors(lock, "x", {"devDependencies": {"a": "^1"}}), [])


class ComposerGraphTest(unittest.TestCase):
    def pkg(self, name: str, **fields: Any) -> dict[str, Any]:
        return {"name": name, "version": "1.0.0", **fields}

    def test_packages_reached_from_composer_json_pass_and_platform_requirements_are_skipped(self) -> None:
        lock = {"packages": [self.pkg("a/a", require={"php": "^8.5", "ext-intl": "*", "b/b": "^1"}), self.pkg("b/b")], "packages-dev": [self.pkg("d/d")]}
        self.assertEqual(ls.composer_orphan_errors(lock, "composer.lock", {"require": {"a/a": "^1"}, "require-dev": {"d/d": "^1"}}), [])

    def test_an_appended_package_is_an_orphan(self) -> None:
        lock = {"packages": [self.pkg("a/a"), self.pkg("wikimedia/composer-merge-plugin")]}
        self.assertEqual(
            ls.composer_orphan_errors(lock, "composer.lock", {"require": {"a/a": "^1"}}),
            ["composer.lock: wikimedia/composer-merge-plugin is not required by composer.json or by any package it reaches"],
        )

    def test_replace_counts_only_for_a_package_reached_on_its_own(self) -> None:
        # laravel/framework replaces illuminate/support and is required itself: fine.
        ok = {"packages": [self.pkg("laravel/framework", replace={"illuminate/support": "self.version"}), self.pkg("x/uses", require={"illuminate/support": "^13"})]}
        self.assertEqual(ls.composer_orphan_errors(ok, "composer.lock", {"require": {"laravel/framework": "^13", "x/uses": "^1"}}), [])
        # An added package that "replaces" a required one, with the real one removed.
        forged = {"packages": [self.pkg("evil/framework", replace={"laravel/framework": "*"})]}
        self.assertEqual(
            ls.composer_orphan_errors(forged, "composer.lock", {"require": {"laravel/framework": "^13"}}),
            ["composer.lock: evil/framework is not required by composer.json or by any package it reaches"],
        )


class VersionTest(unittest.TestCase):
    def test_raising_a_version_passes(self) -> None:
        base = {"packages": [{"name": "a/b", "version": "v13.17.0"}]}
        head = {"packages": [{"name": "a/b", "version": "v13.43.0"}]}
        self.assertEqual(ls.version_errors(base, head, "composer.lock", npm=False), [])

    def test_a_downgrade_or_a_major_change_is_refused(self) -> None:
        base = {"packages": [{"name": "laravel/framework", "version": "v13.43.0"}]}
        for version, word in (("v11.0.0", "major version the base did not hold"), ("v13.42.9", "goes down"), ("v14.0.0", "major version the base did not hold")):
            with self.subTest(version=version):
                head = {"packages": [{"name": "laravel/framework", "version": version}]}
                errors = ls.version_errors(base, head, "composer.lock", npm=False)
                self.assertTrue(errors)
                self.assertIn(word, errors[0])

    def test_npm_versions_are_compared_per_package_name(self) -> None:
        base = npm_lock(**{"node_modules/a": npm_pkg()})
        head = npm_lock(**{"node_modules/a": {**npm_pkg(), "version": "0.9.0"}})
        self.assertEqual(
            ls.version_errors(base, head, "x", npm=True),
            ["x: a 0.9.0 is a major version the base did not hold", "x: a 1.x is gone without a higher major of the base"],
        )

    def test_a_package_moved_in_the_tree_and_downgraded_is_refused(self) -> None:
        # The location changes, the package does not.
        base = npm_lock(**{"node_modules/a": {**npm_pkg(), "version": "2.1.0"}})
        head = npm_lock(**{"node_modules/x/node_modules/a": {**npm_pkg(), "version": "2.0.0"}})
        self.assertEqual(ls.version_errors(base, head, "x", npm=True), ["x: a 2.x goes down below 2.1.0, the highest of the base"])

    def test_dropping_the_higher_of_two_copies_is_a_downgrade(self) -> None:
        base = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "5.3.0"}, "node_modules/p/node_modules/s": {**npm_pkg(), "version": "5.2.0"}})
        head = npm_lock(**{"node_modules/p/node_modules/s": {**npm_pkg(), "version": "5.2.0"}})
        self.assertEqual(ls.version_errors(base, head, "x", npm=True), ["x: s 5.x goes down below 5.3.0, the highest of the base"])

    def test_a_new_copy_below_the_highest_of_its_major_is_refused(self) -> None:
        base = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "5.3.0"}})
        head = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "5.3.0"}, "node_modules/p/node_modules/s": {**npm_pkg(), "version": "5.1.0"}})
        self.assertEqual(ls.version_errors(base, head, "x", npm=True), ["x: s 5.1.0 is new but below 5.3.0, the highest 5.x of the base"])

    def test_a_patch_update_and_a_vanished_lower_major_pass(self) -> None:
        base = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "6.1.0"}, "node_modules/p/node_modules/s": {**npm_pkg(), "version": "5.2.0"}})
        head = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "6.1.1"}})
        self.assertEqual(ls.version_errors(base, head, "x", npm=True), [])

    def test_npm_may_hold_several_versions_but_no_new_major(self) -> None:
        base = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "7.0.0"}, "node_modules/p/node_modules/s": {**npm_pkg(), "version": "5.2.0"}})
        moved_up = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "7.1.0"}, "node_modules/q/node_modules/s": {**npm_pkg(), "version": "5.2.0"}})
        self.assertEqual(ls.version_errors(base, moved_up, "x", npm=True), [])
        new_major = npm_lock(**{"node_modules/s": {**npm_pkg(), "version": "8.0.0"}})
        self.assertEqual(
            ls.version_errors(base, new_major, "x", npm=True),
            [
                "x: s 8.0.0 is a major version the base did not hold",
                "x: s 5.x is gone without a higher major of the base",
                "x: s 7.x is gone without a higher major of the base",
            ],
        )

    def test_the_base_is_read_from_git_and_a_changed_manifest_hands_it_to_review(self) -> None:
        import subprocess

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            git = ["git", "-C", tmp, "-c", "user.name=t", "-c", "user.email=t@example.invalid"]
            subprocess.run([*git, "init", "-q"], check=True)
            (root / "composer.json").write_text(json.dumps({"require": {"a/b": "^1"}}))
            (root / "composer.lock").write_text(json.dumps({"packages": [{**PackagistTest.PKG, "version": "v1.3.0"}]}))
            subprocess.run([*git, "add", "."], check=True)
            subprocess.run([*git, "commit", "-qm", "base"], check=True)
            (root / "composer.lock").write_text(json.dumps({"packages": [PackagistTest.PKG]}))
            out, err = io.StringIO(), io.StringIO()
            with redirect_stdout(out), redirect_stderr(err):
                code = ls.main(["--root", tmp, "--offline", "--base", "HEAD"])
            self.assertEqual(code, 1)
            self.assertIn("a/b 1.x goes down below v1.3.0, the highest of the base", err.getvalue())
            (root / "composer.json").write_text(json.dumps({"require": {"a/b": "^1.2"}}))
            with redirect_stdout(out), redirect_stderr(err):
                code = ls.main(["--root", tmp, "--offline", "--base", "HEAD"])
            self.assertEqual(code, 0)
            self.assertIn("versions are left to the review", out.getvalue())


class CliTest(unittest.TestCase):
    def run_main(self, root: Path) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = ls.main(["--root", str(root), "--offline"])
        return code, out.getvalue(), err.getvalue()

    def test_a_foreign_resolved_url_fails_the_run(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "package-lock.json").write_text(json.dumps(npm_lock(**{"node_modules/a": npm_pkg("https://evil.example/a.tgz")})))
            code, _, err = self.run_main(root)
        self.assertEqual(code, 1)
        self.assertIn("::error::package-lock.json: node_modules/a", err)

    def test_without_offline_the_composer_lock_is_compared_with_packagist(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "composer.lock").write_text(json.dumps({"packages": [PackagistTest.PKG]}))
            (root / "composer.json").write_text(json.dumps({"require": {"a/b": "^1.2"}}))
            out, err = io.StringIO(), io.StringIO()
            with redirect_stdout(out), redirect_stderr(err):
                code = ls.main(["--root", str(root)], fetch=lambda url: {"packages": {"a/b": []}})
        self.assertEqual(code, 1)
        self.assertIn("is not published on Packagist", err.getvalue())

    def test_no_lockfile_at_all_fails(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            code, _, err = self.run_main(Path(tmp))
        self.assertEqual(code, 1)
        self.assertIn("no lockfile", err)

    def test_the_repository_lockfiles_pass(self) -> None:
        code, out, err = self.run_main(ROOT)
        self.assertEqual(code, 0, err)
        self.assertIn(".github/release-tools/package-lock.json", out)


if __name__ == "__main__":
    unittest.main()
