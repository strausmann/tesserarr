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
from typing import Any

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


class AllowlistTest(unittest.TestCase):
    """docker/base-images.env has no code owner; the image behind each pin must not change unreviewed."""

    def test_the_test_pins_are_allowed(self) -> None:
        self.assertEqual(bi.allowlist_errors(bi.parse_pins(PINS)), [])

    def test_another_image_under_a_known_key_is_refused(self) -> None:
        pins = bi.parse_pins(PINS.replace("dunglas/frankenphp:", "evil/frankenphp:"))
        self.assertEqual(
            bi.allowlist_errors(pins),
            [
                "docker/base-images.env: FRANKENPHP_IMAGE must be docker.io/dunglas/frankenphp, "
                "not evil/frankenphp (the allowlist is in scripts/base_images.py)"
            ],
        )

    def test_another_registry_with_the_same_repository_path_is_refused(self) -> None:
        # Same path as the allowed image, only the registry differs.
        cases = {
            "FRANKENPHP_IMAGE": ("dunglas/frankenphp:", "ghcr.io/dunglas/frankenphp:"),
            "COMPOSER_IMAGE": ("composer:2.10.3", "evil.example/library/composer:2.10.3"),
        }
        for key, (old, new) in cases.items():
            with self.subTest(registry=new):
                pins = bi.parse_pins(PINS.replace(old, new))
                errors = bi.allowlist_errors(pins)
                self.assertEqual(len(errors), 1)
                self.assertIn(f"{key} must be", errors[0])
        node = bi.parse_pins(f"NODE_IMAGE=quay.io/library/node:22@{DIGEST_A}\n")
        self.assertEqual(len(bi.allowlist_errors(node)), 1)

    def test_the_short_docker_hub_forms_are_the_same_image(self) -> None:
        for value in ("docker.io/composer", "index.docker.io/library/composer", "composer"):
            with self.subTest(value=value):
                pins = bi.parse_pins(f"COMPOSER_IMAGE={value}:2@{DIGEST_A}\n")
                self.assertEqual(bi.allowlist_errors(pins), [])

    def test_the_explicit_docker_hub_form_is_the_same_image(self) -> None:
        pins = bi.parse_pins(PINS.replace("composer:2.10.3", "docker.io/library/composer:2.10.3"))
        self.assertEqual(bi.allowlist_errors(pins), [])

    def test_a_key_without_an_allowlist_entry_is_refused(self) -> None:
        pins = bi.parse_pins(PINS + f"EXTRA_IMAGE=alpine:3@{DIGEST_A}\n")
        self.assertEqual(
            bi.allowlist_errors(pins),
            ["docker/base-images.env: EXTRA_IMAGE has no entry in the allowlist of scripts/base_images.py"],
        )

    def test_check_includes_the_allowlist(self) -> None:
        pins = bi.parse_pins(PINS.replace("dunglas/frankenphp:", "evil/frankenphp:"))
        self.assertEqual(len(bi.check(pins, DOCKERFILE)), 1)


class BaseComparisonTest(unittest.TestCase):
    BASE = {"FRANKENPHP_IMAGE": f"dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A}"}

    def test_a_raise_within_the_flavour_passes(self) -> None:
        pins = {"FRANKENPHP_IMAGE": f"dunglas/frankenphp:1.14.1-php8.5-trixie@{DIGEST_B}"}
        self.assertEqual(bi.base_errors(self.BASE, pins), [])

    def test_a_downgrade_is_refused(self) -> None:
        pins = {"FRANKENPHP_IMAGE": f"dunglas/frankenphp:1.13.9-php8.5-trixie@{DIGEST_B}"}
        self.assertEqual(
            bi.base_errors(self.BASE, pins),
            ["docker/base-images.env: FRANKENPHP_IMAGE goes down from 1.14.0-php8.5-trixie to 1.13.9-php8.5-trixie"],
        )

    def test_a_flavour_change_is_refused(self) -> None:
        for tag in ("1.14.1-php8.2-trixie", "1.14.1-php8.5-bookworm", "1.0.0-php8.2-bookworm"):
            with self.subTest(tag=tag):
                errors = bi.base_errors(self.BASE, {"FRANKENPHP_IMAGE": f"dunglas/frankenphp:{tag}@{DIGEST_B}"})
                self.assertEqual(len(errors), 1)
                self.assertIn("changes its flavour", errors[0])


class DigestTest(unittest.TestCase):
    def hub(self, digest: str, calls: list[str] | None = None, failures: int = 0) -> Any:
        state = {"failures": failures}

        def fetch(url: str, headers: dict[str, str], method: str) -> Any:
            if calls is not None:
                calls.append(url)
            if state["failures"]:
                state["failures"] -= 1
                raise bi.urllib.error.URLError("blip")
            return {"token": "t"} if method == "GET" else digest

        return fetch

    def setUp(self) -> None:
        self.sleep = bi.time.sleep
        bi.time.sleep = lambda _: None

    def tearDown(self) -> None:
        bi.time.sleep = self.sleep

    def test_a_changed_pin_with_the_served_digest_passes(self) -> None:
        calls: list[str] = []
        pins = {"COMPOSER_IMAGE": f"composer:2.11.1@{DIGEST_B}"}
        self.assertEqual(bi.digest_errors({}, pins, self.hub(DIGEST_B, calls)), [])
        self.assertEqual(calls[-1], "https://registry-1.docker.io/v2/library/composer/manifests/2.11.1")

    def test_a_foreign_digest_under_the_same_tag_is_refused(self) -> None:
        base = {"COMPOSER_IMAGE": f"composer:2.10.3@{DIGEST_A}"}
        pins = {"COMPOSER_IMAGE": f"composer:2.10.3@{DIGEST_B}"}
        errors = bi.digest_errors(base, pins, self.hub(DIGEST_A))
        self.assertEqual(errors, [f"docker/base-images.env: COMPOSER_IMAGE pins {DIGEST_B}, but Docker Hub serves {DIGEST_A} for 2.10.3"])

    def test_an_unchanged_pin_is_not_asked_about(self) -> None:
        calls: list[str] = []
        pins = {"COMPOSER_IMAGE": f"composer:2.10.3@{DIGEST_A}"}
        self.assertEqual(bi.digest_errors(dict(pins), pins, self.hub(DIGEST_B, calls)), [])
        self.assertEqual(calls, [])

    def test_the_registry_is_retried_and_a_lasting_failure_fails(self) -> None:
        pins = {"COMPOSER_IMAGE": f"composer:2.10.3@{DIGEST_A}"}
        self.assertEqual(bi.digest_errors({}, pins, self.hub(DIGEST_A, failures=2)), [])
        errors = bi.digest_errors({}, pins, self.hub(DIGEST_A, failures=3))
        self.assertEqual(len(errors), 1)
        self.assertIn("could not be checked against Docker Hub", errors[0])


class BaseCliTest(unittest.TestCase):
    def test_check_compares_with_the_pins_of_the_base_ref(self) -> None:
        import subprocess

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            git = ["git", "-C", tmp, "-c", "user.name=t", "-c", "user.email=t@example.invalid"]
            subprocess.run([*git, "init", "-q"], check=True)
            (root / "docker").mkdir()
            pins = root / "docker" / "base-images.env"
            pins.write_text(f"FRANKENPHP_IMAGE=dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A}\n")
            subprocess.run([*git, "add", "."], check=True)
            subprocess.run([*git, "commit", "-qm", "base"], check=True)
            pins.write_text(f"FRANKENPHP_IMAGE=dunglas/frankenphp:1.13.0-php8.5-trixie@{DIGEST_B}\n")
            served = lambda url, headers, method: {"token": "t"} if method == "GET" else DIGEST_B  # noqa: E731
            out, err = io.StringIO(), io.StringIO()
            with redirect_stdout(out), redirect_stderr(err):
                code = bi.main(["--pins", str(pins), "--dockerfile", str(root / "none"), "check", "--base", "HEAD"], fetch=served)
            self.assertEqual(code, 1)
            self.assertIn("goes down from 1.14.0-php8.5-trixie to 1.13.0-php8.5-trixie", err.getvalue())

    def test_a_base_ref_that_does_not_resolve_fails_closed(self) -> None:
        import subprocess

        with tempfile.TemporaryDirectory() as tmp:
            root = Path(tmp)
            git = ["git", "-C", tmp, "-c", "user.name=t", "-c", "user.email=t@example.invalid"]
            subprocess.run([*git, "init", "-q"], check=True)
            (root / "docker").mkdir()
            pins = root / "docker" / "base-images.env"
            pins.write_text(f"FRANKENPHP_IMAGE=dunglas/frankenphp:1.14.0-php8.5-trixie@{DIGEST_A}\n")
            (root / "README").write_text("x\n")
            subprocess.run([*git, "add", "README"], check=True)
            subprocess.run([*git, "commit", "-qm", "base without pins"], check=True)
            served = lambda url, headers, method: {"token": "t"} if method == "GET" else DIGEST_A  # noqa: E731
            argv = ["--pins", str(pins), "--dockerfile", str(root / "none"), "check", "--base"]
            for ref, expected in (("origin/does-not-exist", 1), ("HEAD", 0)):
                with self.subTest(ref=ref):
                    out, err = io.StringIO(), io.StringIO()
                    with redirect_stdout(out), redirect_stderr(err):
                        code = bi.main([*argv, ref], fetch=served)
                    self.assertEqual(code, expected, err.getvalue())
                    if expected:
                        self.assertIn("--base origin/does-not-exist is not a commit", err.getvalue())


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

    def test_a_changed_image_fails_without_a_dockerfile_too(self) -> None:
        self.dockerfile.unlink()
        self.pins.write_text(PINS.replace("dunglas/frankenphp:", "evil/frankenphp:"))
        code, _, err = self.run_main("check")
        self.assertEqual(code, 1)
        self.assertIn("::error::docker/base-images.env: FRANKENPHP_IMAGE must be", err)

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
        self.assertEqual(bi.allowlist_errors(pins), [])
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
