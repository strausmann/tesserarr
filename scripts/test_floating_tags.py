# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/floating_tags.py.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import io
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import floating_tags as ft

TAGS = ["v0.0.0", "v0.1.0", "v0.1.1", "v0.2.0", "v1.0.0", "v1.0.1", "v1.1.0", "v1.2.0-rc.1", "archive/go-0.0.2"]


class FloatingTest(unittest.TestCase):
    def test_the_newest_stable_release_gets_every_floating_tag(self) -> None:
        self.assertEqual(ft.floating("1.1.0", TAGS), {"latest": True, "major": True, "minor": True})

    def test_a_rebuild_of_an_older_release_moves_no_floating_tag_of_a_newer_line(self) -> None:
        # The case of a rerun after a newer release: 1.0.1 must not take latest or 1 back.
        self.assertEqual(ft.floating("1.0.1", TAGS), {"latest": False, "major": False, "minor": True})

    def test_an_old_patch_inside_its_minor_line_keeps_no_tag(self) -> None:
        self.assertEqual(ft.floating("0.1.0", TAGS), {"latest": False, "major": False, "minor": False})

    def test_the_newest_of_an_older_major_line_keeps_its_major_tag(self) -> None:
        self.assertEqual(ft.floating("0.2.0", TAGS), {"latest": False, "major": True, "minor": True})

    def test_a_pre_release_never_gets_a_floating_tag_and_is_not_counted(self) -> None:
        self.assertEqual(ft.floating("1.2.0-rc.1", TAGS), {"latest": False, "major": False, "minor": False})
        self.assertTrue(ft.floating("1.1.0", TAGS + ["v9.0.0-rc.1"])["latest"])

    def test_the_built_version_counts_even_before_its_tag_is_listed(self) -> None:
        self.assertEqual(ft.floating("1.2.0", TAGS), {"latest": True, "major": True, "minor": True})

    def test_versions_sort_by_number_not_by_text(self) -> None:
        self.assertTrue(ft.floating("0.10.0", ["v0.9.0", "v0.10.0"])["latest"])
        self.assertFalse(ft.floating("0.9.0", ["v0.9.0", "v0.10.0"])["latest"])

    def test_a_malformed_version_is_refused(self) -> None:
        for bad in ["", "v1.0.0", "1.0", "01.0.0", "1.0.0\nlatest=true"]:
            with self.subTest(bad=bad), self.assertRaises(ValueError):
                ft.floating(bad, TAGS)


class CliTest(unittest.TestCase):
    def test_prints_github_output_lines(self) -> None:
        out = io.StringIO()
        with redirect_stdout(out):
            code = ft.main(["--version", "1.0.1", "--tags-from", "-"], stdin=io.StringIO("\n".join(TAGS)))
        self.assertEqual(code, 0)
        self.assertEqual(out.getvalue(), "latest=false\nmajor=false\nminor=true\n")

    def test_reads_the_tags_of_a_git_repository(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            repo = Path(tmp)

            def git(*args: str) -> None:
                subprocess.run(["git", *args], cwd=repo, check=True, capture_output=True)

            git("init", "-q", "-b", "main")
            git("config", "user.name", "Test")
            git("config", "user.email", "test@example.invalid")
            git("config", "commit.gpgsign", "false")
            git("commit", "-q", "--allow-empty", "-m", "chore: seed")
            for tag in ("v0.1.0", "v0.2.0"):
                git("tag", tag)
            out = io.StringIO()
            with redirect_stdout(out):
                code = ft.main(["--version", "0.1.0", "--repo", str(repo)])
        self.assertEqual(code, 0)
        self.assertIn("latest=false\n", out.getvalue())

    def test_a_malformed_version_fails(self) -> None:
        out = io.StringIO()
        with redirect_stdout(out):
            code = ft.main(["--version", "latest", "--tags-from", "-"], stdin=io.StringIO(""))
        self.assertEqual(code, 1)


if __name__ == "__main__":
    unittest.main()
