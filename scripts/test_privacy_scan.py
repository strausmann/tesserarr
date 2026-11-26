# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/privacy_scan.py.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import io
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import privacy_scan as ps


def scan(text: str) -> list[str]:
    return ps.findings_in(text, "page.md")


class FindingsTest(unittest.TestCase):
    def test_finds_a_tailscale_address(self) -> None:
        self.assertEqual(len(scan("reach it at 100.64.0.1 over the tailnet")), 1)

    def test_finds_an_address_at_the_end_of_a_sentence(self) -> None:
        self.assertEqual(len(scan("The central answers on 100.64.0.1.")), 1)

    def test_finds_private_ranges(self) -> None:
        for address in ("10.1.2.3", "172.16.0.9", "172.31.255.1", "192.168.178.20"):
            with self.subTest(address=address):
                self.assertEqual(len(scan(f"host {address}")), 1)

    def test_accepts_the_documentation_ranges(self) -> None:
        self.assertEqual(scan("192.0.2.10, 198.51.100.7 and 203.0.113.5"), [])

    def test_accepts_version_numbers(self) -> None:
        self.assertEqual(scan("astro 10.1.2.3.4 and 1.100.64.0.1"), [])

    def test_accepts_addresses_outside_the_private_ranges(self) -> None:
        self.assertEqual(scan("100.63.0.1 and 100.128.0.1 and 172.32.0.1"), [])

    def test_finds_host_names_of_a_real_installation(self) -> None:
        self.assertEqual(len(scan("ssh into hhdocker01")), 1)
        self.assertEqual(len(scan("see media.strausmann.cloud")), 1)

    def test_finds_an_email_address_outside_the_allowed_ones(self) -> None:
        self.assertEqual(len(scan("write to someone@gmail.com")), 1)

    def test_accepts_the_project_addresses_and_example_domains(self) -> None:
        self.assertEqual(scan("projects@strausmann.ch, security@strausmann.ch, alice@example.org, bob@mail.example.com"), [])

    def test_names_file_and_line(self) -> None:
        self.assertEqual(ps.findings_in("ok\nat 100.64.0.1\n", "docs/a.md"), ["docs/a.md:2: private address 100.64.0.1"])


class MainTest(unittest.TestCase):
    def test_exits_1_on_a_page_with_a_tailscale_address(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            page = Path(tmp) / "docs" / "install" / "tunnels.md"
            page.parent.mkdir(parents=True)
            page.write_text("---\ntitle: t\n---\nThe central answers on 100.64.0.1.\n", encoding="utf-8")
            err = io.StringIO()
            with redirect_stderr(err), redirect_stdout(io.StringIO()):
                code = ps.main([str(Path(tmp) / "docs")])
        self.assertEqual(code, 1)
        self.assertIn("100.64.0.1", err.getvalue())

    def test_exits_0_on_clean_pages(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            (Path(tmp) / "index.md").write_text("Use 192.0.2.10 and media.example.org.\n", encoding="utf-8")
            with redirect_stdout(io.StringIO()):
                self.assertEqual(ps.main([tmp]), 0)

    def test_scans_only_the_given_suffixes(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            dist = Path(tmp)
            (dist / "_astro").mkdir()
            (dist / "_astro" / "ui.js").write_text("translated by someone@gmail.com\n", encoding="utf-8")
            (dist / "index.html").write_text("<p>media.example.org</p>\n", encoding="utf-8")
            with redirect_stdout(io.StringIO()), redirect_stderr(io.StringIO()):
                self.assertEqual(ps.main(["--suffix", ".html", tmp]), 0)
                self.assertEqual(ps.main([tmp]), 1)
            (dist / "index.html").write_text("<p>100.64.0.1</p>\n", encoding="utf-8")
            err = io.StringIO()
            with redirect_stdout(io.StringIO()), redirect_stderr(err):
                self.assertEqual(ps.main(["--suffix", ".html", tmp]), 1)
            self.assertIn("index.html:1: private address 100.64.0.1", err.getvalue())

    def test_exits_1_on_a_path_that_does_not_exist(self) -> None:
        err = io.StringIO()
        with redirect_stderr(err):
            self.assertEqual(ps.main(["/no/such/dir/for/the/scan"]), 1)


if __name__ == "__main__":
    unittest.main()
