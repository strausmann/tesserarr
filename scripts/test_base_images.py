# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/base_images.py.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import io
import os
import re
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path

sys.path.insert(0, str(Path(__file__).resolve().parent))

import base_images as bi

DIGEST_A = "sha256:" + "a" * 64
DIGEST_B = "sha256:" + "b" * 64

PINS = f"""\
# comment
FRANKENPHP_IMAGE=dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A}

COMPOSER_IMAGE=composer:2.10.3@{DIGEST_B}
"""

DOCKERFILE = """\
# syntax=docker/dockerfile:1
ARG FRANKENPHP_IMAGE
ARG COMPOSER_IMAGE
FROM ${COMPOSER_IMAGE} AS composer
FROM ${FRANKENPHP_IMAGE} AS php-base
ARG TESSERARR_VERSION=0.0.0-dev
"""


class ParseTest(unittest.TestCase):
    def test_comments_and_blank_lines_are_skipped_and_order_is_kept(self) -> None:
        pins = bi.parse_pins(PINS)
        self.assertEqual(
            list(pins.items()),
            [
                ("FRANKENPHP_IMAGE", f"dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A}"),
                ("COMPOSER_IMAGE", f"composer:2.10.3@{DIGEST_B}"),
            ],
        )

    def test_a_registry_with_a_port_is_accepted(self) -> None:
        pins = bi.parse_pins(f"X_IMAGE=registry.example.com:5000/a/b:1.0@{DIGEST_A}\n")
        self.assertEqual(pins["X_IMAGE"], f"registry.example.com:5000/a/b:1.0@{DIGEST_A}")

    def test_every_malformed_line_is_refused_with_its_line_number(self) -> None:
        cases = {
            "no digest": "A_IMAGE=composer:2.10.3\n",
            "no tag (Renovate needs one)": f"A_IMAGE=composer@{DIGEST_A}\n",
            "short digest": "A_IMAGE=composer:2@sha256:abc\n",
            "upper-case digest": f"A_IMAGE=composer:2@sha256:{'A' * 64}\n",
            "key without _IMAGE": f"COMPOSER=composer:2@{DIGEST_A}\n",
            "lower-case key": f"a_IMAGE=composer:2@{DIGEST_A}\n",
            "spaces around =": f"A_IMAGE = composer:2@{DIGEST_A}\n",
            "quoted value": f"A_IMAGE='composer:2@{DIGEST_A}'\n",
            "shell expansion": f"A_IMAGE=$(id):2@{DIGEST_A}\n",
            "trailing text": f"A_IMAGE=composer:2@{DIGEST_A} # pinned\n",
            "export prefix": f"export A_IMAGE=composer:2@{DIGEST_A}\n",
        }
        for name, text in cases.items():
            with self.subTest(case=name):
                with self.assertRaises(bi.PinError) as caught:
                    bi.parse_pins("# header\n" + text)
                self.assertIn("line 2", str(caught.exception))

    def test_a_key_listed_twice_is_refused(self) -> None:
        text = f"A_IMAGE=composer:2@{DIGEST_A}\nA_IMAGE=composer:3@{DIGEST_B}\n"
        with self.assertRaisesRegex(bi.PinError, "line 2: A_IMAGE is listed twice"):
            bi.parse_pins(text)

    def test_an_empty_file_is_refused(self) -> None:
        with self.assertRaisesRegex(bi.PinError, "no pin"):
            bi.parse_pins("# only a comment\n\n")


class DockerfileCheckTest(unittest.TestCase):
    def test_pins_that_match_the_dockerfile_pass(self) -> None:
        self.assertEqual(bi.check(bi.parse_pins(PINS), DOCKERFILE), [])

    def test_an_image_arg_with_a_default_is_a_second_pin(self) -> None:
        dockerfile = DOCKERFILE.replace(
            "ARG COMPOSER_IMAGE\n", f"ARG COMPOSER_IMAGE=composer:2.10.3@{DIGEST_B}\n"
        )
        errors = bi.check(bi.parse_pins(PINS), dockerfile)
        self.assertEqual(
            errors,
            ["docker/Dockerfile: ARG COMPOSER_IMAGE has a default; the pin belongs in docker/base-images.env only"],
        )

    def test_an_image_arg_without_a_pin_is_reported(self) -> None:
        dockerfile = DOCKERFILE.replace("ARG COMPOSER_IMAGE\n", "ARG COMPOSER_IMAGE\nARG NODE_IMAGE\n")
        self.assertEqual(
            bi.check(bi.parse_pins(PINS), dockerfile),
            ["docker/Dockerfile: ARG NODE_IMAGE has no pin in docker/base-images.env"],
        )

    def test_a_pin_the_dockerfile_does_not_use_is_reported(self) -> None:
        pins = bi.parse_pins(PINS + f"NODE_IMAGE=node:22@{DIGEST_A}\n")
        self.assertEqual(
            bi.check(pins, DOCKERFILE),
            ["docker/base-images.env: NODE_IMAGE is not an ARG of docker/Dockerfile"],
        )

    def test_an_image_arg_declared_twice_counts_once(self) -> None:
        # A stage may redeclare a global ARG to use it again.
        dockerfile = DOCKERFILE + "FROM php-base AS app\nARG FRANKENPHP_IMAGE\n"
        self.assertEqual(bi.check(bi.parse_pins(PINS), dockerfile), [])


class CliTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)
        self.pins = self.dir / "base-images.env"
        self.pins.write_text(PINS)
        self.dockerfile = self.dir / "Dockerfile"
        self.dockerfile.write_text(DOCKERFILE)

    def tearDown(self) -> None:
        self.tmp.cleanup()

    def run_main(self, *args: str) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = bi.main(["--pins", str(self.pins), "--dockerfile", str(self.dockerfile), *args])
        return code, out.getvalue(), err.getvalue()

    def test_build_args_prints_one_key_value_per_line(self) -> None:
        code, out, _ = self.run_main("build-args")
        self.assertEqual(code, 0)
        self.assertEqual(
            out.splitlines(),
            [f"FRANKENPHP_IMAGE=dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A}", f"COMPOSER_IMAGE=composer:2.10.3@{DIGEST_B}"],
        )

    def test_cli_prints_build_arg_options_for_a_shell(self) -> None:
        code, out, _ = self.run_main("cli")
        self.assertEqual(code, 0)
        self.assertEqual(
            out.strip(),
            f"--build-arg FRANKENPHP_IMAGE=dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A} "
            f"--build-arg COMPOSER_IMAGE=composer:2.10.3@{DIGEST_B}",
        )

    def test_github_output_writes_a_multiline_value_with_a_unique_delimiter(self) -> None:
        output = self.dir / "github_output"
        output.write_text("existing=1\n")
        old = os.environ.get("GITHUB_OUTPUT")
        os.environ["GITHUB_OUTPUT"] = str(output)
        try:
            code, _, _ = self.run_main("github-output", "--name", "build-args")
        finally:
            if old is None:
                del os.environ["GITHUB_OUTPUT"]
            else:
                os.environ["GITHUB_OUTPUT"] = old
        self.assertEqual(code, 0)
        lines = output.read_text().splitlines()
        self.assertEqual(lines[0], "existing=1")
        self.assertRegex(lines[1], r"^build-args<<PINS_[0-9a-f]{32}$")
        delimiter = lines[1].split("<<", 1)[1]
        self.assertEqual(lines[-1], delimiter)
        self.assertEqual(len(lines), 5)

    def test_a_mismatch_with_the_dockerfile_fails_every_command(self) -> None:
        self.dockerfile.write_text(DOCKERFILE + "ARG NODE_IMAGE\n")
        for command in ("check", "build-args", "cli"):
            with self.subTest(command=command):
                code, out, err = self.run_main(command)
                self.assertEqual(code, 1)
                self.assertEqual(out, "")
                self.assertIn("::error::docker/Dockerfile: ARG NODE_IMAGE has no pin", err)

    def test_without_a_dockerfile_only_the_pins_are_checked(self) -> None:
        # main carries the pins before the application (and its Dockerfile) arrives.
        self.dockerfile.unlink()
        code, out, _ = self.run_main("check")
        self.assertEqual(code, 0)
        self.assertIn("2 base image pins", out)

    def test_a_malformed_pin_file_fails(self) -> None:
        self.pins.write_text("A_IMAGE=composer:2\n")
        code, _, err = self.run_main("check")
        self.assertEqual(code, 1)
        self.assertIn("::error::docker/base-images.env line 1", err)

    def test_the_repository_pins_pass(self) -> None:
        # The real file, and the real Dockerfile where the branch has one.
        root = Path(__file__).resolve().parent.parent
        pins = bi.parse_pins((root / "docker" / "base-images.env").read_text())
        self.assertIn("FRANKENPHP_IMAGE", pins)
        dockerfile = root / "docker" / "Dockerfile"
        if dockerfile.exists():
            self.assertEqual(bi.check(pins, dockerfile.read_text()), [])


class WorkflowBuildArgsTest(unittest.TestCase):
    """Every image build in a workflow passes the pins.

    A text check, not a behaviour test: a workflow does not run outside GitHub
    Actions. It counts the build steps of docker/Dockerfile and the pin
    arguments per file, so a comment that names either line would also count.
    """

    def test_each_build_of_the_dockerfile_gets_the_pins(self) -> None:
        workflows = Path(__file__).resolve().parent.parent / ".github" / "workflows"
        checked = 0
        for path in sorted(workflows.glob("*.yml")):
            text = path.read_text()
            builds = len(re.findall(r"^\s+file: (?:release/)?docker/Dockerfile$", text, re.MULTILINE))
            if not builds:
                continue
            checked += builds
            with self.subTest(workflow=path.name):
                self.assertEqual(text.count("${{ steps.pins.outputs.build-args }}"), builds)
        self.assertGreaterEqual(checked, 4, "ci.yml (2), docker-publish.yml and image-rebuild.yml build the image")


if __name__ == "__main__":
    unittest.main()
