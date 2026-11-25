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

    def test_a_path_package_inside_the_repository_passes_and_one_outside_fails(self) -> None:
        inside = {"name": "x/y", "dist": {"type": "path", "url": "packages/laravel-jmap-client"}}
        outside = {"name": "x/z", "dist": {"type": "path", "url": "../../etc"}}
        self.assertEqual(ls.composer_errors(self.lock(inside), "composer.lock"), [])
        self.assertEqual(len(ls.composer_errors(self.lock(outside), "composer.lock")), 1)

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

    def test_a_path_dist_does_not_end_the_check_of_its_source(self) -> None:
        good = {"name": "x/y", "dist": {"type": "path", "url": "packages/y"}, "source": {"type": "git", "url": "https://github.com/x/y.git", "reference": REF}}
        foreign = {**good, "source": {"type": "git", "url": "https://evil.example/x/y.git", "reference": REF}}
        self.assertEqual(ls.composer_errors(self.lock(good), "composer.lock"), [])
        self.assertEqual(len(ls.composer_errors(self.lock(foreign), "composer.lock")), 1)

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

        errors = ls.packagist_errors({"packages": [self.PKG]}, "composer.lock", down)
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
