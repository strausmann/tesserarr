# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Unit tests for scripts/trivy_report.py.

Trivy itself never runs here: the docker calls go through an injected runner.

Run with: python -m pytest scripts/
"""

from __future__ import annotations

import io
import json
import os
import subprocess
import sys
import tempfile
import unittest
from contextlib import redirect_stderr, redirect_stdout
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parent))

import trivy_report as tr

UNAVAILABLE = "FATAL\tFatal error\tinit error: DB error: failed to download vulnerability DB: OCI repository error"


def vuln(vid: str, severity: str, pkg: str = "libxml2", fixed: str = "", installed: str = "2.9.14") -> dict[str, Any]:
    entry = {"VulnerabilityID": vid, "PkgName": pkg, "InstalledVersion": installed, "Severity": severity}
    if fixed:
        entry["FixedVersion"] = fixed
    return entry


def report(*vulns: dict[str, Any], artifact: str = "img", os_family: str = "debian") -> dict[str, Any]:
    meta: dict[str, Any] = {}
    if os_family:
        meta["OS"] = {"Family": os_family, "Name": "13.2"}
    return {
        "ArtifactName": artifact,
        "Metadata": meta,
        "Results": [{"Target": "img (debian 13.2)", "Class": "os-pkgs", "Vulnerabilities": list(vulns)}],
    }


class Runner:
    """Records docker calls and answers them from a script of (rc, stdout, stderr)."""

    def __init__(self, *answers: tuple[int, str, str]) -> None:
        self.answers = list(answers)
        self.calls: list[list[str]] = []

    def __call__(self, cmd: list[str], **_: Any) -> subprocess.CompletedProcess[str]:
        self.calls.append(cmd)
        rc, out, err = self.answers.pop(0)
        return subprocess.CompletedProcess(cmd, rc, out, err)


class ClassifyTest(unittest.TestCase):
    def test_only_a_failed_database_download_counts_as_unavailable(self) -> None:
        self.assertEqual(tr.classify_db_attempt(0, "ok"), "ok")
        self.assertEqual(tr.classify_db_attempt(1, UNAVAILABLE), "unavailable")
        self.assertEqual(tr.classify_db_attempt(1, "FATAL\tunable to initialize the cache"), "error")
        self.assertEqual(tr.classify_db_attempt(125, UNAVAILABLE), "error")
        self.assertEqual(tr.classify_db_attempt(2, UNAVAILABLE), "error")


class FetchDbTest(unittest.TestCase):
    def setUp(self) -> None:
        self.sleeps: list[int] = []
        self.out = io.StringIO()

    def fetch(self, runner: Runner) -> bool:
        with redirect_stdout(self.out):
            return tr.fetch_db(Path("/cache"), run=runner, sleep=self.sleeps.append)

    def test_the_image_is_pulled_first_and_the_first_repository_that_answers_wins(self) -> None:
        runner = Runner((0, "", ""), (1, UNAVAILABLE, ""), (0, "downloaded", ""))
        self.assertTrue(self.fetch(runner))
        self.assertEqual(runner.calls[0], ["docker", "pull", "--quiet", tr.TRIVY_IMAGE])
        self.assertIn("mirror.gcr.io/aquasec/trivy-db:2", runner.calls[1])
        self.assertIn("ghcr.io/aquasecurity/trivy-db:2", runner.calls[2])
        self.assertEqual(self.sleeps, [])

    def test_three_rounds_without_a_database_report_unavailable(self) -> None:
        runner = Runner((0, "", ""), *[(1, "", UNAVAILABLE)] * 6)
        self.assertFalse(self.fetch(runner))
        self.assertEqual(len(runner.calls), 7)
        self.assertEqual(self.sleeps, [20, 40])

    def test_a_failed_pull_of_the_trivy_image_is_an_error_not_unavailable(self) -> None:
        with self.assertRaisesRegex(tr.TrivyError, "docker pull"):
            self.fetch(Runner((1, "", "pull access denied")))

    def test_any_other_failure_stops_at_once(self) -> None:
        runner = Runner((0, "", ""), (125, "", "docker: Error response from daemon"))
        with self.assertRaisesRegex(tr.TrivyError, "exit code 125"):
            self.fetch(runner)
        self.assertEqual(len(runner.calls), 2)

    def test_every_call_uses_the_pinned_image_and_the_cache(self) -> None:
        runner = Runner((0, "", ""), (0, "", ""))
        self.fetch(runner)
        call = runner.calls[1]
        self.assertIn(tr.TRIVY_IMAGE, call)
        self.assertIn("/cache:/root/.cache/trivy", call)
        self.assertEqual(call[call.index(tr.TRIVY_IMAGE) + 1 :][:3], ["image", "--download-db-only", "--no-progress"])


class ScanTest(unittest.TestCase):
    def test_the_scan_never_updates_the_database_and_parses_json(self) -> None:
        runner = Runner((0, json.dumps(report(vuln("CVE-1", "HIGH"))), ""))
        data = tr.scan("img", Path("/cache"), run=runner)
        self.assertEqual(data["ArtifactName"], "img")
        args = runner.calls[0][runner.calls[0].index(tr.TRIVY_IMAGE) + 1 :]
        self.assertEqual(
            args,
            ["image", "--skip-db-update", "--scanners", "vuln", "--format", "json", "--quiet", "--exit-code", "0", "img"],
        )

    def test_a_failing_scan_is_an_error(self) -> None:
        with self.assertRaisesRegex(tr.TrivyError, "scan of img failed"):
            tr.scan("img", Path("/cache"), run=Runner((1, "", "boom")))

    def test_output_that_is_not_a_report_is_an_error(self) -> None:
        with self.assertRaisesRegex(tr.TrivyError, "not a Trivy report"):
            tr.scan("img", Path("/cache"), run=Runner((0, "[]", "")))


class SummaryTest(unittest.TestCase):
    def test_the_summary_has_the_count_table_and_the_sorted_critical_and_high_rows(self) -> None:
        data = report(
            vuln("CVE-3", "HIGH", pkg="curl", fixed="8.1"),
            vuln("CVE-1", "CRITICAL"),
            vuln("CVE-3", "HIGH", pkg="libcurl4", fixed="8.1"),
            vuln("CVE-1", "CRITICAL"),
            vuln("CVE-9", "LOW"),
        )
        self.assertEqual(
            tr.summary(data, "tesserarr:ci").splitlines(),
            [
                "### Trivy: `tesserarr:ci`",
                "",
                "| Severity | Findings | With a fix | Unique CVEs |",
                "|---|---|---|---|",
                "| CRITICAL | 2 | 0 | 1 |",
                "| HIGH | 2 | 2 | 1 |",
                "| MEDIUM | 0 | 0 | 0 |",
                "| LOW | 1 | 0 | 1 |",
                "",
                "| Severity | ID | Package | Installed | Fixed |",
                "|---|---|---|---|---|",
                "| CRITICAL | CVE-1 | libxml2 | 2.9.14 |  |",
                "| HIGH | CVE-3 | curl | 2.9.14 | 8.1 |",
                "| HIGH | CVE-3 | libcurl4 | 2.9.14 | 8.1 |",
            ],
        )

    def test_a_pipe_in_a_cell_cannot_break_the_table(self) -> None:
        data = report(vuln("CVE-1", "HIGH", pkg="a|b\nc"))
        self.assertIn("| HIGH | CVE-1 | a\\|b c |", tr.summary(data, "x"))

    def test_a_report_without_results_counts_zero(self) -> None:
        self.assertIn("| CRITICAL | 0 | 0 | 0 |", tr.summary({"ArtifactName": "x"}, "x"))


class CompareTest(unittest.TestCase):
    PUBLISHED = report(vuln("CVE-1", "CRITICAL"), vuln("CVE-2", "HIGH"), vuln("CVE-3", "HIGH", pkg="a"), vuln("CVE-3", "HIGH", pkg="b"))

    def decide(self, rebuilt: dict[str, Any]) -> dict[str, Any]:
        return tr.decide(self.PUBLISHED, rebuilt)

    def test_fewer_high_findings_and_no_more_critical_is_better(self) -> None:
        result = self.decide(report(vuln("CVE-1", "CRITICAL"), vuln("CVE-2", "HIGH")))
        self.assertEqual(result["result"], "better")
        self.assertEqual(
            {k: result[k] for k in ("published_critical", "published_high", "rebuilt_critical", "rebuilt_high")},
            {"published_critical": 1, "published_high": 2, "rebuilt_critical": 1, "rebuilt_high": 1},
        )

    def test_fewer_critical_and_no_more_high_is_better(self) -> None:
        result = self.decide(report(vuln("CVE-2", "HIGH"), vuln("CVE-3", "HIGH")))
        self.assertEqual(result["result"], "better")

    def test_the_same_counts_are_not_better(self) -> None:
        self.assertEqual(self.decide(self.PUBLISHED)["result"], "not-better")

    def test_more_critical_is_never_better_even_with_far_fewer_high(self) -> None:
        result = self.decide(report(vuln("CVE-1", "CRITICAL"), vuln("CVE-7", "CRITICAL")))
        self.assertEqual(result["result"], "not-better")

    def test_more_high_is_never_better_even_with_far_fewer_critical(self) -> None:
        # Fewer findings in total (2 < 4), but one high more.
        published = report(vuln("CVE-1", "CRITICAL"), vuln("CVE-2", "CRITICAL"), vuln("CVE-3", "CRITICAL"), vuln("CVE-4", "HIGH"))
        rebuilt = report(vuln("CVE-4", "HIGH"), vuln("CVE-5", "HIGH"))
        self.assertEqual(tr.decide(published, rebuilt)["result"], "not-better")

    def test_counts_are_unique_cves_not_package_rows(self) -> None:
        # CVE-3 sits in two packages of the published image; fixing it in one
        # package only is not a fix.
        rebuilt = report(vuln("CVE-1", "CRITICAL"), vuln("CVE-2", "HIGH"), vuln("CVE-3", "HIGH", pkg="a"))
        self.assertEqual(self.decide(rebuilt)["result"], "not-better")

    def test_medium_and_low_do_not_count(self) -> None:
        rebuilt = report(vuln("CVE-1", "CRITICAL"), vuln("CVE-2", "HIGH"), *(vuln(f"M-{n}", "MEDIUM") for n in range(9)))
        self.assertEqual(self.decide(rebuilt)["result"], "better")

    def test_a_report_without_a_detected_os_gives_no_result(self) -> None:
        # Zero findings because Trivy saw no OS packages is not a clean image.
        result = self.decide(report(os_family=""))
        self.assertEqual(result["result"], "invalid")
        self.assertIn("no operating system", result["reason"])

    def test_a_report_without_os_package_results_gives_no_result(self) -> None:
        rebuilt = {"ArtifactName": "img", "Metadata": {"OS": {"Family": "debian"}}, "Results": []}
        self.assertEqual(self.decide(rebuilt)["result"], "invalid")

    def test_different_operating_systems_give_no_result(self) -> None:
        result = self.decide(report(os_family="alpine"))
        self.assertEqual(result["result"], "invalid")
        self.assertIn("debian", result["reason"])


class UnfixedCriticalTest(unittest.TestCase):
    REBUILT = report(
        vuln("CVE-1", "CRITICAL"),
        vuln("CVE-1", "CRITICAL"),
        vuln("CVE-2", "CRITICAL", pkg="openssl", fixed="3.1"),
        vuln("CVE-4", "CRITICAL", pkg="zlib"),
        vuln("CVE-5", "HIGH"),
    )

    def issue(self, number: int, state: str, ids: list[str], created: str = "2026-11-01T00:00:00Z") -> dict[str, Any]:
        return {"number": number, "state": state, "createdAt": created, "body": tr.issue_body([(i, "p", "1") for i in ids], "0.1.0")}

    def test_only_critical_findings_without_a_fix_count(self) -> None:
        self.assertEqual(tr.unfixed_critical(self.REBUILT), [("CVE-1", "libxml2", "2.9.14"), ("CVE-4", "zlib", "2.9.14")])

    def test_no_finding_and_no_issue_does_nothing(self) -> None:
        plan = tr.plan_issue(report(vuln("CVE-5", "HIGH")), [], "0.1.0")
        self.assertEqual(plan["action"], "none")

    def test_new_findings_without_an_issue_open_one(self) -> None:
        plan = tr.plan_issue(self.REBUILT, [], "0.1.0")
        self.assertEqual(plan["action"], "create")
        self.assertEqual(plan["new"], ["CVE-1", "CVE-4"])
        self.assertEqual(plan["title"], tr.ISSUE_TITLE)
        self.assertIn(tr.ISSUE_MARKER, plan["body"])
        self.assertIn("| CVE-4 | zlib | 2.9.14 |", plan["body"])

    def test_known_findings_change_nothing(self) -> None:
        plan = tr.plan_issue(self.REBUILT, [self.issue(7, "OPEN", ["CVE-1", "CVE-4"])], "0.1.0")
        self.assertEqual(plan["action"], "none")

    def test_a_new_finding_updates_the_open_issue_and_keeps_the_known_ones(self) -> None:
        plan = tr.plan_issue(self.REBUILT, [self.issue(7, "OPEN", ["CVE-1", "CVE-0"])], "0.1.0")
        self.assertEqual(plan["action"], "update")
        self.assertEqual(plan["number"], 7)
        self.assertEqual(plan["new"], ["CVE-4"])
        self.assertEqual(tr.known_ids(plan["body"]), {"CVE-0", "CVE-1", "CVE-4"})
        self.assertIn("CVE-4", plan["comment"])
        self.assertNotIn("CVE-1", plan["comment"])

    def test_a_closed_issue_still_counts_as_known_but_a_new_finding_opens_a_new_issue(self) -> None:
        closed = self.issue(3, "CLOSED", ["CVE-1", "CVE-4"])
        self.assertEqual(tr.plan_issue(self.REBUILT, [closed], "0.1.0")["action"], "none")
        plan = tr.plan_issue(self.REBUILT, [self.issue(3, "CLOSED", ["CVE-1"])], "0.1.0")
        self.assertEqual(plan["action"], "create")
        self.assertEqual(plan["new"], ["CVE-4"])

    def test_the_newest_marked_issue_decides_and_unmarked_issues_are_ignored(self) -> None:
        stranger = {"number": 9, "state": "OPEN", "createdAt": "2026-11-20T00:00:00Z", "body": "CVE-4 is bad"}
        older = self.issue(2, "OPEN", ["CVE-1", "CVE-4"], created="2026-10-01T00:00:00Z")
        newer = self.issue(5, "OPEN", ["CVE-1"], created="2026-11-10T00:00:00Z")
        plan = tr.plan_issue(self.REBUILT, [stranger, older, newer], "0.1.0")
        self.assertEqual((plan["action"], plan["number"]), ("update", 5))

    def test_ids_in_the_marker_must_look_like_ids(self) -> None:
        body = f"{tr.ISSUE_MARKER}\n<!-- ids: CVE-1 $(id) GHSA-xxxx-yyyy-zzzz -->"
        self.assertEqual(tr.known_ids(body), {"CVE-1", "GHSA-xxxx-yyyy-zzzz"})


class CliTest(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.dir = Path(self.tmp.name)
        self.env = {
            "GITHUB_OUTPUT": str(self.dir / "output"),
            "GITHUB_STEP_SUMMARY": str(self.dir / "summary"),
            "RUNNER_TEMP": str(self.dir),
        }
        self.saved = {k: os.environ.get(k) for k in self.env}
        os.environ.update(self.env)

    def tearDown(self) -> None:
        for key, value in self.saved.items():
            if value is None:
                os.environ.pop(key, None)
            else:
                os.environ[key] = value
        self.tmp.cleanup()

    def run_main(self, argv: list[str], runner: Runner) -> tuple[int, str, str]:
        out, err = io.StringIO(), io.StringIO()
        with redirect_stdout(out), redirect_stderr(err):
            code = tr.main(argv, run=runner, sleep=lambda _: None)
        return code, out.getvalue(), err.getvalue()

    def outputs(self) -> dict[str, str]:
        path = Path(self.env["GITHUB_OUTPUT"])
        if not path.exists():
            return {}
        return dict(line.split("=", 1) for line in path.read_text().splitlines())

    def test_report_without_a_database_succeeds_with_a_warning_and_no_table(self) -> None:
        runner = Runner((0, "", ""), *[(1, UNAVAILABLE, "")] * 6)
        code, out, _ = self.run_main(["report", "tesserarr:ci"], runner)
        self.assertEqual(code, 0)
        self.assertIn("::warning title=Trivy::scan skipped: database unavailable", out)
        summary = Path(self.env["GITHUB_STEP_SUMMARY"]).read_text()
        self.assertIn("**scan skipped: database unavailable**", summary)
        self.assertNotIn("| Severity |", summary)

    def test_report_with_a_database_writes_the_table(self) -> None:
        runner = Runner((0, "", ""), (0, "", ""), (0, json.dumps(report(vuln("CVE-1", "HIGH"))), ""))
        code, _, _ = self.run_main(["report", "tesserarr:ci"], runner)
        self.assertEqual(code, 0)
        self.assertIn("| HIGH | 1 | 0 | 1 |", Path(self.env["GITHUB_STEP_SUMMARY"]).read_text())

    def test_report_fails_when_trivy_fails_with_a_database(self) -> None:
        runner = Runner((0, "", ""), (0, "", ""), (1, "", "scan failed"))
        code, out, _ = self.run_main(["report", "tesserarr:ci"], runner)
        self.assertEqual(code, 1)
        self.assertIn("::error title=Trivy::", out)

    def test_compare_without_a_database_says_unavailable_and_never_better(self) -> None:
        runner = Runner((0, "", ""), *[(1, UNAVAILABLE, "")] * 6)
        code, _, _ = self.run_main(["compare", "--published", "a", "--rebuilt", "b", "--out", str(self.dir)], runner)
        self.assertEqual(code, 0)
        self.assertEqual(self.outputs()["result"], "unavailable")
        self.assertFalse((self.dir / "rebuilt.json").exists())

    def test_compare_scans_both_with_one_database_and_writes_the_counts(self) -> None:
        published = report(vuln("CVE-1", "CRITICAL"), vuln("CVE-2", "HIGH"), artifact="a")
        rebuilt = report(vuln("CVE-1", "CRITICAL"), artifact="b")
        runner = Runner((0, "", ""), (0, "", ""), (0, json.dumps(published), ""), (0, json.dumps(rebuilt), ""))
        code, _, _ = self.run_main(["compare", "--published", "a", "--rebuilt", "b", "--out", str(self.dir)], runner)
        self.assertEqual(code, 0)
        downloads = [c for c in runner.calls if "--download-db-only" in c]
        self.assertEqual(len(downloads), 1)
        self.assertEqual(
            self.outputs(),
            {
                "result": "better",
                "published_critical": "1",
                "published_high": "1",
                "rebuilt_critical": "1",
                "rebuilt_high": "0",
            },
        )
        self.assertEqual(json.loads((self.dir / "rebuilt.json").read_text())["ArtifactName"], "b")
        summary = Path(self.env["GITHUB_STEP_SUMMARY"]).read_text()
        self.assertIn("### Trivy: `a`", summary)
        self.assertIn("### Trivy: `b`", summary)

    def test_compare_fails_on_an_invalid_report(self) -> None:
        published = report(vuln("CVE-1", "CRITICAL"), artifact="a")
        rebuilt = report(artifact="b", os_family="")
        runner = Runner((0, "", ""), (0, "", ""), (0, json.dumps(published), ""), (0, json.dumps(rebuilt), ""))
        code, out, _ = self.run_main(["compare", "--published", "a", "--rebuilt", "b", "--out", str(self.dir)], runner)
        self.assertEqual(code, 1)
        self.assertEqual(self.outputs()["result"], "invalid")
        self.assertIn("::error title=Trivy::", out)

    def test_issue_plan_reads_files_and_writes_json(self) -> None:
        (self.dir / "rebuilt.json").write_text(json.dumps(report(vuln("CVE-1", "CRITICAL"))))
        (self.dir / "issues.json").write_text("[]")
        code, out, _ = self.run_main(
            ["issue-plan", "--report", str(self.dir / "rebuilt.json"), "--issues", str(self.dir / "issues.json"), "--version", "0.1.0"],
            Runner(),
        )
        self.assertEqual(code, 0)
        self.assertEqual(json.loads(out)["action"], "create")

    def test_issue_plan_refuses_a_version_that_is_not_semver(self) -> None:
        (self.dir / "rebuilt.json").write_text(json.dumps(report()))
        (self.dir / "issues.json").write_text("[]")
        code, _, err = self.run_main(
            ["issue-plan", "--report", str(self.dir / "rebuilt.json"), "--issues", str(self.dir / "issues.json"), "--version", "0.1.0\nx"],
            Runner(),
        )
        self.assertEqual(code, 1)
        self.assertIn("version", err)


if __name__ == "__main__":
    unittest.main()
