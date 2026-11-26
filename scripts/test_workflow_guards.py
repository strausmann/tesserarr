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


class DockerHubRequestTest(unittest.TestCase):
    """Request bodies never travel inside a curl config (a 401 at login was traced there)."""

    def step(self) -> str:
        blocks = run_blocks((WORKFLOWS / "dockerhub-description.yml").read_text())
        found = [b for b in blocks if "https://hub.docker.com/v2/auth/token" in b]
        self.assertEqual(len(found), 1)
        return found[0]

    def test_no_body_inside_a_curl_config(self) -> None:
        self.assertNotRegex(self.step(), r"data\s*=")

    def test_login_body_comes_from_stdin(self) -> None:
        step = self.step()
        self.assertIn("jq -nc '{identifier: env.DOCKERHUB_ADMIN_USERNAME, secret: env.DOCKERHUB_TOKEN_ADMIN}'", step)
        self.assertIn("--data-binary @- https://hub.docker.com/v2/auth/token", step)

    def test_patch_body_comes_from_a_private_file(self) -> None:
        step = self.step()
        self.assertIn("umask 077", step)
        self.assertIn('--data-binary @"$patch"', step)

    def test_no_secret_is_a_command_argument(self) -> None:
        # Only the shell builtin `[ -z … ]` may expand the token; it starts no process.
        for line in self.step().splitlines():
            if re.search(r"\$\{?DOCKERHUB_TOKEN_ADMIN", line):
                self.assertRegex(line, r'^if \[ -z "\$DOCKERHUB_ADMIN_USERNAME" \] \|\| \[ -z "\$DOCKERHUB_TOKEN_ADMIN" \]; then$')


class CosignVerifyDocsTest(unittest.TestCase):
    """cosign v2 cannot see the v3 signature bundles, so every documented verify says v3."""

    DOCS = ["CONTRIBUTING.md", "SECURITY.md", "README.md", "docs/dockerhub/README.md"]

    def test_every_documented_verify_names_cosign_v3_and_a_container_command(self) -> None:
        checked = 0
        for name in self.DOCS:
            path = ROOT / name
            if not path.exists():
                continue
            text = path.read_text()
            if "cosign verify" not in text and "cosign/cosign:" not in text:
                continue
            checked += 1
            with self.subTest(file=name):
                self.assertIn("cosign v3 or newer", text)
                self.assertRegex(text, r"docker run --rm ghcr\.io/sigstore/cosign/cosign:v3\.[0-9]+\.[0-9]+ verify ")
                self.assertNotRegex(text, r"cosign/cosign:v[0-2]\.")
        self.assertGreaterEqual(checked, 2, "no document with a cosign verify command found")


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


class PreviewRedeployTest(unittest.TestCase):
    """The test instance webhook: URL and token are secrets and never reach a command line.

    Source-text guards (no behaviour test outside GitHub Actions); they see characters, not
    meaning, so each looks for the exact line.
    """

    def text(self) -> str:
        return (WORKFLOWS / "preview-image.yml").read_text()

    def step(self) -> str:
        found = [b for b in run_blocks(self.text()) if "curl" in b]
        self.assertEqual(len(found), 1, "preview-image.yml: expected one curl step")
        return found[0]

    def test_the_header_and_the_url_come_from_a_curl_config_on_stdin(self) -> None:
        step = self.step()
        self.assertIn("curl -sS -K -", step)
        self.assertIn('header = "X-Gitlab-Token: %s"', step)
        self.assertNotIn("-H ", step)
        self.assertNotIn("--header", step)

    def test_url_and_token_are_secrets_not_literals(self) -> None:
        text = self.text()
        self.assertIn("${{ secrets.TESTSTACK_WEBHOOK_URL }}", text)
        self.assertIn("${{ secrets.TESTSTACK_WEBHOOK_TOKEN }}", text)
        self.assertNotRegex(text, r"https?://[^\s]*strausmann\.(cloud|de|net)")

    def test_missing_secrets_end_the_job_green(self) -> None:
        step = self.step()
        self.assertIn('if [ -z "$WEBHOOK_URL" ] || [ -z "$WEBHOOK_TOKEN" ]; then', step)
        self.assertIn("exit 0", step)

    def test_redeploy_runs_only_after_a_successful_publish(self) -> None:
        text = self.text()
        job = text[text.index("  redeploy:\n") :]
        self.assertIn("needs: publish", job)
        self.assertIn("if: needs.publish.result == 'success'", job)

    def test_rolling_tag_is_gated_and_validated(self) -> None:
        text = (WORKFLOWS / "docker-publish.yml").read_text()
        lines = [line for line in text.splitlines() if "inputs.rolling-tag ||" in line]
        self.assertEqual(len(lines), 1)
        self.assertIn("steps.tag.outputs.flavor == 'release' && inputs.rolling-tag != ''", lines[0])
        self.assertTrue(any('"$ROLLING_TAG" == "${INPUT_TAG%-rc.*}-preview"' in b for b in run_blocks(text)))


if __name__ == "__main__":
    unittest.main()
