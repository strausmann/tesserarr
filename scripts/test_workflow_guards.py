# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Structural guards for the release workflows and the script tests.

These read files as text; a behaviour test is not possible for a workflow
outside GitHub Actions. They cannot tell a comment from a command, which is why
each check looks for the exact command line, not for a word.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import ast
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
WORKFLOWS = ROOT / ".github" / "workflows"
SIGNED_REFS = r"signed_refs='^refs/(heads/main|heads/milestone/[0-9]+\.[0-9]+\.(0|x))$'"


def run_blocks(text: str) -> list[str]:
    """The `run: |` bodies of a workflow, without comment lines."""
    blocks, current, indent = [], None, 0
    for line in text.splitlines():
        if current is not None:
            if line.strip() and (len(line) - len(line.lstrip())) <= indent:
                blocks.append("\n".join(current))
                current = None
            else:
                if not line.strip().startswith("#"):
                    current.append(line.strip())
                continue
        match = re.match(r"^(\s*)run: \|\s*$", line)
        if match:
            current, indent = [], len(match.group(1))
    if current is not None:
        blocks.append("\n".join(current))
    return blocks


class SignedPreviewTest(unittest.TestCase):
    def test_preview_refuses_branches_whose_images_are_not_signed(self) -> None:
        blocks = run_blocks((WORKFLOWS / "preview-image.yml").read_text())
        guard = [b for b in blocks if SIGNED_REFS in b]
        self.assertEqual(len(guard), 1, "preview-image.yml: no ref guard before the build")
        self.assertIn('if ! [[ "$REF" =~ $signed_refs ]]; then', guard[0])
        self.assertIn("exit 1", guard[0])

    def test_docker_publish_refuses_an_unsigned_release_or_preview(self) -> None:
        blocks = run_blocks((WORKFLOWS / "docker-publish.yml").read_text())
        guard = [b for b in blocks if SIGNED_REFS in b and '[ -n "$INPUT_TAG" ]' in b]
        self.assertEqual(len(guard), 1, "docker-publish.yml: no ref guard for tagged images")
        self.assertIn("exit 1", guard[0])

    def test_the_guards_use_the_same_refs_as_the_signing_step(self) -> None:
        text = (WORKFLOWS / "docker-publish.yml").read_text()
        self.assertEqual(text.count(SIGNED_REFS), 2, "guard and signing step must list the same refs")


class FloatingTagsTest(unittest.TestCase):
    """x.y, x and latest move only through scripts/floating_tags.py."""

    def test_the_merge_job_asks_floating_tags_and_fetches_every_tag(self) -> None:
        text = (WORKFLOWS / "docker-publish.yml").read_text()
        blocks = run_blocks(text)
        self.assertTrue(
            any('python3 scripts/floating_tags.py --version "$TAG"' in b for b in blocks),
            "docker-publish.yml: floating_tags.py is not called",
        )
        merge = text[text.index("  merge:\n") :]
        self.assertIn("fetch-depth: 0", merge[: merge.index("- name: Resolve built revision")])

    def test_each_floating_tag_is_gated_by_its_answer(self) -> None:
        text = (WORKFLOWS / "docker-publish.yml").read_text()
        for pattern, key in (
            ("type=semver,pattern={{major}}.{{minor}},", "minor"),
            ("type=semver,pattern={{major}},", "major"),
            ("type=raw,value=latest,", "latest"),
        ):
            lines = [line for line in text.splitlines() if pattern in line]
            with self.subTest(tag=key):
                self.assertEqual(len(lines), 1)
                self.assertIn(f"steps.floating.outputs.{key} == 'true'", lines[0])


class ScriptTestLayoutTest(unittest.TestCase):
    """`python scripts/test_x.py` and pytest must run the same tests."""

    def test_the_main_block_is_the_last_statement(self) -> None:
        for path in sorted(Path(__file__).resolve().parent.glob("test_*.py")):
            body = ast.parse(path.read_text()).body
            mains = [
                i
                for i, node in enumerate(body)
                if isinstance(node, ast.If) and "__main__" in ast.unparse(node.test)
            ]
            with self.subTest(file=path.name):
                for index in mains:
                    self.assertEqual(index, len(body) - 1, f"{path.name}: code after the __main__ block")


if __name__ == "__main__":
    unittest.main()
