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


def npm_pkg(url: str = "https://registry.npmjs.org/a/-/a-1.0.0.tgz", integrity: str = SHA) -> dict[str, Any]:
    entry: dict[str, Any] = {"version": "1.0.0", "resolved": url}
    if integrity:
        entry["integrity"] = integrity
    return entry


def composer_pkg(dist: str = "https://api.github.com/repos/a/b/zipball/abc", source: str = "https://github.com/a/b.git") -> dict[str, Any]:
    return {
        "name": "a/b",
        "dist": {"type": "zip", "url": dist},
        "source": {"type": "git", "url": source},
    }


class NpmTest(unittest.TestCase):
    def test_registry_packages_with_sha512_pass(self) -> None:
        self.assertEqual(ls.npm_errors(npm_lock(**{"node_modules/a": npm_pkg()}), "package-lock.json"), [])

    def test_bundled_and_linked_entries_need_no_resolved(self) -> None:
        lock = npm_lock(**{"node_modules/a/node_modules/b": {"version": "1", "inBundle": True}, "node_modules/c": {"link": True, "resolved": "packages/c"}})
        self.assertEqual(ls.npm_errors(lock, "package-lock.json"), [])

    def test_a_foreign_resolved_url_is_refused(self) -> None:
        cases = {
            "other host": "https://evil.example/a/-/a-1.0.0.tgz",
            "http": "http://registry.npmjs.org/a/-/a-1.0.0.tgz",
            "lookalike host": "https://registry.npmjs.org.evil.example/a.tgz",
            "user info": "https://registry.npmjs.org@evil.example/a.tgz",
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

    def test_a_link_outside_the_repository_is_refused(self) -> None:
        errors = ls.npm_errors(npm_lock(**{"node_modules/c": {"link": True, "resolved": "../elsewhere"}}), "x")
        self.assertEqual(len(errors), 1)


class ComposerTest(unittest.TestCase):
    def lock(self, *packages: dict[str, Any], dev: tuple[dict[str, Any], ...] = ()) -> dict[str, Any]:
        return {"packages": list(packages), "packages-dev": list(dev)}

    def test_github_and_packagist_pass(self) -> None:
        lock = self.lock(
            composer_pkg(),
            composer_pkg(dist="https://codeload.github.com/a/b/legacy.zip/abc"),
            composer_pkg(dist="https://repo.packagist.org/files/a/b.zip"),
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

    def test_a_package_without_dist_and_source_is_refused(self) -> None:
        self.assertEqual(len(ls.composer_errors(self.lock({"name": "a/b"}), "composer.lock")), 1)


class CliTest(unittest.TestCase):
    def run_main(self, root: Path) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = ls.main(["--root", str(root)])
        return code, out.getvalue(), err.getvalue()

    def test_a_foreign_resolved_url_fails_the_run(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            (root / "package-lock.json").write_text(json.dumps(npm_lock(**{"node_modules/a": npm_pkg("https://evil.example/a.tgz")})))
            code, _, err = self.run_main(root)
        self.assertEqual(code, 1)
        self.assertIn("::error::package-lock.json: node_modules/a", err)

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
