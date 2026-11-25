#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Trivy scans of the image: the CI report and the weekly rebuild comparison.

One Trivy release (TRIVY_IMAGE) and one database logic for both callers, so a
rebuild is judged exactly like the CI report of a pull request.

``report IMAGE``
    ci.yml, job "Docker — build smoke test". Fetches the database, scans IMAGE
    and writes the counts and the critical and high findings to the job
    summary. Findings never fail the step.

``compare --published A --rebuilt B --out DIR``
    image-rebuild.yml. Fetches the database ONCE and scans both images with it
    (``--skip-db-update``), so both are judged against the same data. Writes
    ``result=better|not-better|unavailable|invalid`` and the critical and high
    counts to $GITHUB_OUTPUT, and DIR/published.json, DIR/rebuilt.json.

``issue-plan --report R --issues I --version V``
    Which issue to open or update for critical findings without a fix in R.
    I is ``gh issue list --json number,state,body,createdAt``. Prints JSON.

The database is fetched first: up to three rounds over the OCI repositories in
DB_REPOSITORIES (Trivy's own defaults, see the Trivy docs, "Database
Locations"), one repository per call. Trivy itself falls back from one
--db-repository to the next only on transient errors (429, 5xx), not when a
registry cannot be resolved or reached. Only a failed database download counts
as "database unavailable": Trivy exits 1 on a fatal error (cmd/trivy/main.go,
log.Fatal) and names this one "failed to download vulnerability DB"
(pkg/db/db.go). Anything else is an error: the Trivy image is pulled first
without a fallback, so a wrong pin, a pull limit or a Docker error is red. A
missing database is no finding and no defect of the image, so ``report``
succeeds without a table and ``compare`` says ``unavailable``: never "better",
never an issue.

Counts are unique vulnerability IDs per severity (a CVE in four packages of
one source counts once). A rebuild is ``better`` when it has no more critical
and no more high IDs than the published image and fewer of both together. A
report without a detected operating system or without OS package results, or
two reports of different operating systems, are ``invalid``: zero findings of
a scan that saw nothing is not a clean image.

Standard library only. Text from a report (package names, versions) is data:
it is escaped for Markdown and never reaches a shell.
"""

from __future__ import annotations

import argparse
import json
import os
import re
import subprocess
import sys
import time
from collections.abc import Callable, Iterable
from pathlib import Path
from typing import Any

# Renovate updates this pin (renovate.json, custom manager for scripts/*.py).
TRIVY_IMAGE = "aquasec/trivy:0.76.2@sha256:8f6bb947dbc528902e11f2f5be52f7b519f6a39d5d148f41166f2040499768fb"
DB_REPOSITORIES = ("mirror.gcr.io/aquasec/trivy-db:2", "ghcr.io/aquasecurity/trivy-db:2")
DB_UNAVAILABLE = "failed to download vulnerability DB"
SEVERITIES = ("CRITICAL", "HIGH", "MEDIUM", "LOW")

ISSUE_TITLE = "Image: critical vulnerabilities without a fix"
ISSUE_MARKER = "<!-- tesserarr:unfixed-critical -->"
IDS_RE = re.compile(r"<!-- ids: ([^>]*) -->")
ID_RE = re.compile(r"\A[A-Z][A-Z0-9]*-[0-9A-Za-z][0-9A-Za-z-]*\Z")
VERSION_RE = re.compile(r"\A[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?\Z")

Run = Callable[..., "subprocess.CompletedProcess[str]"]


class TrivyError(RuntimeError):
    """Trivy or Docker failed for a reason other than an unavailable database."""


def _run(cmd: list[str], **kwargs: Any) -> subprocess.CompletedProcess[str]:
    return subprocess.run(cmd, capture_output=True, text=True, check=False, **kwargs)


def trivy_command(cache: Path, *args: str) -> list[str]:
    return [
        "docker", "run", "--rm",
        "-v", "/var/run/docker.sock:/var/run/docker.sock",
        "-v", f"{cache}:/root/.cache/trivy",
        TRIVY_IMAGE, *args,
    ]  # fmt: skip


def classify_db_attempt(rc: int, output: str) -> str:
    """``ok``, ``unavailable`` (only a failed database download) or ``error``."""
    if rc == 0:
        return "ok"
    if rc == 1 and DB_UNAVAILABLE in output:
        return "unavailable"
    return "error"


def fetch_db(
    cache: Path,
    run: Run = _run,
    sleep: Callable[[int], Any] = time.sleep,
    repositories: Iterable[str] = DB_REPOSITORIES,
    rounds: int = 3,
) -> bool:
    """True when the database is in ``cache``, False when it is unavailable."""
    pulled = run(["docker", "pull", "--quiet", TRIVY_IMAGE])
    if pulled.returncode != 0:
        raise TrivyError(f"docker pull {TRIVY_IMAGE} failed with exit code {pulled.returncode}")
    repositories = list(repositories)
    for round_no in range(1, rounds + 1):
        for repo in repositories:
            done = run(trivy_command(cache, "image", "--download-db-only", "--no-progress", "--db-repository", repo))
            output = (done.stdout or "") + (done.stderr or "")
            print(output.rstrip("\n"))
            kind = classify_db_attempt(done.returncode, output)
            if kind == "ok":
                return True
            if kind == "error":
                raise TrivyError(f"Trivy failed with exit code {done.returncode}, not on the database download")
            print(f"Trivy database from {repo}, round {round_no} of {rounds}: failed")
        if round_no < rounds:
            sleep(round_no * 20)
    return False


def scan(image: str, cache: Path, run: Run = _run) -> dict[str, Any]:
    """The JSON report of ``image``, scanned with the database already in ``cache``."""
    done = run(
        trivy_command(
            cache, "image", "--skip-db-update", "--scanners", "vuln", "--format", "json",
            "--quiet", "--exit-code", "0", image,
        )
    )  # fmt: skip
    if done.returncode != 0:
        sys.stderr.write(done.stderr or "")
        raise TrivyError(f"scan of {image} failed with exit code {done.returncode}")
    try:
        data = json.loads(done.stdout)
    except json.JSONDecodeError as exc:
        raise TrivyError(f"scan of {image}: not a Trivy report ({exc})") from None
    if not isinstance(data, dict):
        raise TrivyError(f"scan of {image}: not a Trivy report")
    return data


def vulnerabilities(report: dict[str, Any]) -> list[dict[str, Any]]:
    found: list[dict[str, Any]] = []
    for result in report.get("Results") or []:
        found.extend(v for v in result.get("Vulnerabilities") or [] if isinstance(v, dict))
    return found


def _cell(value: Any) -> str:
    return str(value if value is not None else "").replace("|", "\\|").replace("\r", " ").replace("\n", " ")


def summary(report: dict[str, Any], title: str) -> str:
    """The same tables the CI report has always written."""
    vulns = vulnerabilities(report)
    lines = [
        f"### Trivy: `{_cell(title)}`",
        "",
        "| Severity | Findings | With a fix | Unique CVEs |",
        "|---|---|---|---|",
    ]
    for severity in SEVERITIES:
        found = [v for v in vulns if v.get("Severity") == severity]
        fixed = [v for v in found if (v.get("FixedVersion") or "") != ""]
        unique = {v.get("VulnerabilityID") for v in found}
        lines.append(f"| {severity} | {len(found)} | {len(fixed)} | {len(unique)} |")
    lines += ["", "| Severity | ID | Package | Installed | Fixed |", "|---|---|---|---|---|"]
    rows = {
        "| "
        + " | ".join(_cell(v.get(k)) for k in ("Severity", "VulnerabilityID", "PkgName", "InstalledVersion"))
        + f" | {_cell(v.get('FixedVersion') or '')} |"
        for v in vulns
        if v.get("Severity") in ("CRITICAL", "HIGH")
    }
    lines += sorted(rows)
    return "\n".join(lines) + "\n"


def unavailable_summary(title: str) -> str:
    return (
        f"### Trivy: `{_cell(title)}`\n\n"
        f"**scan skipped: database unavailable** ({' '.join(DB_REPOSITORIES)})\n"
    )


def _unique(report: dict[str, Any], severity: str) -> set[str]:
    return {str(v.get("VulnerabilityID")) for v in vulnerabilities(report) if v.get("Severity") == severity}


def _validity(report: dict[str, Any]) -> str | None:
    family = ((report.get("Metadata") or {}).get("OS") or {}).get("Family")
    if not family:
        return "no operating system detected"
    if not any(r.get("Class") == "os-pkgs" for r in report.get("Results") or []):
        return "no OS package results"
    return None


def decide(published: dict[str, Any], rebuilt: dict[str, Any]) -> dict[str, Any]:
    """Compare the critical and high findings of two reports scanned with one database."""
    for name, data in (("published", published), ("rebuilt", rebuilt)):
        problem = _validity(data)
        if problem:
            return {"result": "invalid", "reason": f"{name} image: {problem}"}
    families = [((d.get("Metadata") or {}).get("OS") or {}).get("Family") for d in (published, rebuilt)]
    if families[0] != families[1]:
        return {"result": "invalid", "reason": f"different operating systems: {families[0]} and {families[1]}"}
    counts = {
        "published_critical": len(_unique(published, "CRITICAL")),
        "published_high": len(_unique(published, "HIGH")),
        "rebuilt_critical": len(_unique(rebuilt, "CRITICAL")),
        "rebuilt_high": len(_unique(rebuilt, "HIGH")),
    }
    better = (
        counts["rebuilt_critical"] <= counts["published_critical"]
        and counts["rebuilt_high"] <= counts["published_high"]
        and counts["rebuilt_critical"] + counts["rebuilt_high"]
        < counts["published_critical"] + counts["published_high"]
    )
    return {"result": "better" if better else "not-better", **counts}


def unfixed_critical(report: dict[str, Any]) -> list[tuple[str, str, str]]:
    """(ID, package, installed version) of every critical finding without a fixed version."""
    found = {
        (str(v.get("VulnerabilityID")), str(v.get("PkgName")), str(v.get("InstalledVersion") or ""))
        for v in vulnerabilities(report)
        if v.get("Severity") == "CRITICAL" and not (v.get("FixedVersion") or "")
    }
    return sorted(found)


def known_ids(body: str) -> set[str]:
    match = IDS_RE.search(body or "")
    if not match:
        return set()
    return {token for token in match.group(1).split() if ID_RE.match(token)}


def issue_body(findings: list[tuple[str, str, str]], version: str, known: Iterable[str] = ()) -> str:
    ids = sorted({f[0] for f in findings} | set(known))
    rows = [f"| {_cell(i)} | {_cell(p)} | {_cell(v)} |" for i, p, v in findings]
    return "\n".join(
        [
            ISSUE_MARKER,
            f"<!-- ids: {' '.join(ids)} -->",
            "",
            f"The weekly rebuild of release `{version}` (`.github/workflows/image-rebuild.yml`) found critical",
            "vulnerabilities in the image that have no fixed version yet. A rebuild cannot remove them; they go",
            "away when the base image or the distribution ships a fix, and the weekly rebuild then publishes a",
            "patch release. Close this issue once they are gone or assessed; a later finding that is not listed",
            "here opens a new one.",
            "",
            "| ID | Package | Installed |",
            "|---|---|---|",
            *rows,
            "",
        ]
    )


def plan_issue(report: dict[str, Any], issues: list[dict[str, Any]], version: str) -> dict[str, Any]:
    """``none``, ``create`` or ``update`` (the newest issue that carries the marker)."""
    findings = unfixed_critical(report)
    marked = [i for i in issues if ISSUE_MARKER in (i.get("body") or "")]
    latest = max(marked, key=lambda i: i.get("createdAt") or "", default=None)
    known = known_ids(latest["body"]) if latest else set()
    new = sorted({f[0] for f in findings} - known)
    if not new:
        return {"action": "none", "new": []}
    if latest and str(latest.get("state", "")).upper() == "OPEN":
        return {
            "action": "update",
            "number": int(latest["number"]),
            "new": new,
            "body": issue_body(findings, version, known),
            "comment": f"New critical findings without a fix in the rebuild of `{version}`: "
            + ", ".join(f"`{i}`" for i in new),
        }
    return {"action": "create", "title": ISSUE_TITLE, "new": new, "body": issue_body(findings, version)}


def _append(env: str, text: str) -> None:
    path = os.environ.get(env)
    if path:
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(text)


def _cache() -> Path:
    cache = Path(os.environ.get("RUNNER_TEMP", "/tmp")) / "trivy-cache"
    cache.mkdir(parents=True, exist_ok=True)
    return cache


def run_report(image: str, run: Run, sleep: Callable[[int], Any]) -> int:
    cache = _cache()
    try:
        if not fetch_db(cache, run=run, sleep=sleep):
            print("::warning title=Trivy::scan skipped: database unavailable")
            text = unavailable_summary(image)
            print(text)
            _append("GITHUB_STEP_SUMMARY", text)
            return 0
        text = summary(scan(image, cache, run=run), image)
    except TrivyError as exc:
        print(f"::error title=Trivy::{exc}")
        return 1
    print(text)
    _append("GITHUB_STEP_SUMMARY", text)
    return 0


def run_compare(published: str, rebuilt: str, out: Path, run: Run, sleep: Callable[[int], Any]) -> int:
    cache = _cache()
    try:
        if not fetch_db(cache, run=run, sleep=sleep):
            print("::warning title=Trivy::no comparison: database unavailable")
            _append("GITHUB_OUTPUT", "result=unavailable\n")
            _append("GITHUB_STEP_SUMMARY", unavailable_summary(f"{published} / {rebuilt}"))
            return 0
        reports = {"published": scan(published, cache, run=run), "rebuilt": scan(rebuilt, cache, run=run)}
    except TrivyError as exc:
        print(f"::error title=Trivy::{exc}")
        return 1
    out.mkdir(parents=True, exist_ok=True)
    for name, data in reports.items():
        (out / f"{name}.json").write_text(json.dumps(data), encoding="utf-8")
    _append("GITHUB_STEP_SUMMARY", summary(reports["published"], published) + "\n" + summary(reports["rebuilt"], rebuilt))
    result = decide(reports["published"], reports["rebuilt"])
    _append("GITHUB_OUTPUT", "".join(f"{k}={v}\n" for k, v in result.items() if k != "reason"))
    if result["result"] == "invalid":
        print(f"::error title=Trivy::no comparison: {result['reason']}")
        return 1
    print(
        f"critical/high: published {result['published_critical']}/{result['published_high']}, "
        f"rebuilt {result['rebuilt_critical']}/{result['rebuilt_high']}: {result['result']}"
    )
    return 0


def main(argv: list[str] | None = None, run: Run = _run, sleep: Callable[[int], Any] = time.sleep) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    sub = parser.add_subparsers(dest="command", required=True)
    rep = sub.add_parser("report", help="CI report of one image")
    rep.add_argument("image")
    cmp_ = sub.add_parser("compare", help="published image against its rebuild")
    cmp_.add_argument("--published", required=True)
    cmp_.add_argument("--rebuilt", required=True)
    cmp_.add_argument("--out", type=Path, required=True)
    plan = sub.add_parser("issue-plan", help="issue for critical findings without a fix")
    plan.add_argument("--report", type=Path, required=True)
    plan.add_argument("--issues", type=Path, required=True)
    plan.add_argument("--version", required=True)
    args = parser.parse_args(argv)

    if args.command == "report":
        return run_report(args.image, run, sleep)
    if args.command == "compare":
        return run_compare(args.published, args.rebuilt, args.out, run, sleep)
    if not VERSION_RE.match(args.version):
        print("::error::issue-plan: the version is not a semantic version", file=sys.stderr)
        return 1
    report = json.loads(args.report.read_text(encoding="utf-8"))
    issues = json.loads(args.issues.read_text(encoding="utf-8"))
    print(json.dumps(plan_issue(report, issues, args.version)))
    return 0


if __name__ == "__main__":
    sys.exit(main())
