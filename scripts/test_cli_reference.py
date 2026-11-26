# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/cli_reference.py.

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

import cli_reference as cr


def option(name: str, description: str, default: Any = None, accept_value: bool = True) -> dict[str, Any]:
    return {
        "name": f"--{name}",
        "shortcut": "",
        "accept_value": accept_value,
        "is_value_required": False,
        "is_multiple": False,
        "description": description,
        "default": default,
    }


def listing(*commands: dict[str, Any]) -> dict[str, Any]:
    standard = {"help": option("help", "Display help", False, False), "quiet": option("quiet", "Quiet", False, False)}
    return {
        "application": {"name": "Laravel Framework", "version": "13.0.0"},
        "commands": [
            {
                "name": "migrate",
                "description": "Run the database migrations",
                "usage": ["migrate"],
                "definition": {"arguments": {}, "options": dict(standard)},
            },
            *[
                {**c, "definition": {**c["definition"], "options": {**standard, **c["definition"]["options"]}}}
                for c in commands
            ],
        ],
    }


MIGRATE = {
    "name": "tesserarr:migrate",
    "description": "Back up the database, then run the pending migrations",
    "usage": ["tesserarr:migrate [--lock-timeout [LOCK-TIMEOUT]]"],
    "definition": {
        "arguments": {},
        "options": {"lock-timeout": option("lock-timeout", "seconds to wait for another tesserarr:migrate", "300")},
    },
}

INVITE = {
    "name": "tesserarr:invite",
    "description": "Create an invitation",
    "usage": ["tesserarr:invite <name>"],
    "definition": {
        "arguments": {
            "name": {
                "name": "name",
                "is_required": True,
                "is_array": False,
                "description": "who the invitation is for | a label",
                "default": None,
            }
        },
        "options": {},
    },
}

RACE = {
    "name": "tesserarr:test:race",
    "description": "Test only: run one side of a race",
    "usage": ["tesserarr:test:race"],
    "definition": {"arguments": {}, "options": {}},
}


class RenderTest(unittest.TestCase):
    def test_lists_only_tesserarr_commands_sorted_by_name(self) -> None:
        names = [c["name"] for c in cr.tesserarr_commands(listing(MIGRATE, INVITE))]
        self.assertEqual(names, ["tesserarr:invite", "tesserarr:migrate"])

    def test_leaves_out_test_only_commands(self) -> None:
        names = [c["name"] for c in cr.tesserarr_commands(listing(MIGRATE, RACE))]
        self.assertEqual(names, ["tesserarr:migrate"])

    def test_renders_a_command_with_its_usage_and_own_options(self) -> None:
        page = cr.render(listing(MIGRATE), "en")
        self.assertIn("## `tesserarr:migrate`", page)
        self.assertIn("Back up the database, then run the pending migrations", page)
        self.assertIn("tesserarr:migrate [--lock-timeout [LOCK-TIMEOUT]]", page)
        self.assertIn("| `--lock-timeout` | seconds to wait for another tesserarr:migrate | `300` |", page)
        self.assertNotIn("--quiet", page)
        self.assertNotIn("--help", page)

    def test_escapes_a_pipe_in_a_table_cell(self) -> None:
        page = cr.render(listing(INVITE), "en")
        self.assertIn("| `name` | yes | who the invitation is for \\| a label |", page)

    def test_writes_the_german_page_with_german_headings(self) -> None:
        page = cr.render(listing(MIGRATE), "de")
        self.assertIn("title: Befehlszeile", page)
        self.assertIn("| Option | Beschreibung | Standard |", page)

    def test_says_so_when_there_is_no_command(self) -> None:
        self.assertIn("No `tesserarr` command yet.", cr.render(listing(), "en"))


class MainTest(unittest.TestCase):
    def test_writes_both_pages(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            source = root / "list.json"
            source.write_text(json.dumps(listing(MIGRATE)), encoding="utf-8")
            with redirect_stdout(io.StringIO()):
                code = cr.main([str(source), "--docs", str(root / "docs")])
            self.assertEqual(code, 0)
            self.assertIn("tesserarr:migrate", (root / "docs/reference/cli.md").read_text(encoding="utf-8"))
            self.assertIn("Befehlszeile", (root / "docs/de/reference/cli.md").read_text(encoding="utf-8"))

    def test_exits_1_on_a_listing_that_is_not_json(self) -> None:
        with tempfile.TemporaryDirectory() as tmp:
            source = Path(tmp) / "list.json"
            source.write_text("not json", encoding="utf-8")
            err = io.StringIO()
            with redirect_stderr(err):
                code = cr.main([str(source), "--docs", str(Path(tmp) / "docs")])
            self.assertEqual(code, 1)
            self.assertIn("not JSON", err.getvalue())


if __name__ == "__main__":
    unittest.main()
