#!/usr/bin/env python3
# SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

"""Real-data scan of the published documentation website.

The website is public (GitHub Pages), so the `website` workflow runs this over
its sources: private IPv4 ranges (RFC 1918 and the 100.64.0.0/10 range of
carrier-grade NAT and Tailscale), host names and domains of a real
installation, and e-mail addresses other than the project addresses and the
reserved example domains. An address may end a sentence: a full stop after it
is no version number. The patterns are the ones of the repository-wide
`Privacy / secret scan` job in .github/workflows/ci.yml; keep both in step.
Examples use the documentation ranges 192.0.2.0/24, 198.51.100.0/24 and
203.0.113.0/24 and the domain example.org (CONTRIBUTING.md, "No environment
details, ever").

With ``--suffix`` only files with that ending are read. The built site
(website/dist) is scanned as ``--suffix .html --suffix .xml``: its JavaScript
bundles (_astro/, pagefind/) are third-party code whose credits name their
translators' e-mail addresses, while every page and the sitemap are ours.

Standard library only. Prints each finding with file and line, exits 1 when
there is one.
"""

from __future__ import annotations

import argparse
import re
import sys
from pathlib import Path

ADDRESSES = [
    re.compile(r"(?<![0-9.])10\.[0-9]+\.[0-9]+\.[0-9]+(?![0-9]|\.[0-9])"),
    re.compile(r"(?<![0-9.])172\.(?:1[6-9]|2[0-9]|3[01])\.[0-9]+\.[0-9]+(?![0-9]|\.[0-9])"),
    re.compile(r"(?<![0-9.])192\.168\.[0-9]+\.[0-9]+(?![0-9]|\.[0-9])"),
    re.compile(r"(?<![0-9.])100\.(?:6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\.[0-9]+\.[0-9]+(?![0-9]|\.[0-9])"),
]
NAMES = [re.compile(p) for p in (r"hhdocker", r"hhplex", r"strausmann\.(?:cloud|de|net)\b")]
EMAIL = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")
ALLOWED_EMAIL = re.compile(
    r"^(?:(?:projects|security)@strausmann\.ch|.+@(?:[A-Za-z0-9-]+\.)*(?:example\.(?:com|org|net)|invalid|test))$",
    re.IGNORECASE,
)
SKIP_DIRS = {"node_modules", ".astro", "dist", ".git"}


def findings_in(text: str, name: str) -> list[str]:
    found: list[str] = []
    for number, line in enumerate(text.splitlines(), start=1):
        for pattern in ADDRESSES:
            found += [f"{name}:{number}: private address {m.group(0)}" for m in pattern.finditer(line)]
        for pattern in NAMES:
            found += [f"{name}:{number}: host or domain of an installation {m.group(0)}" for m in pattern.finditer(line)]
        found += [
            f"{name}:{number}: e-mail address {m.group(0)}"
            for m in EMAIL.finditer(line)
            if not ALLOWED_EMAIL.match(m.group(0))
        ]
    return found


def files_under(path: Path, suffixes: tuple[str, ...] = ()) -> list[Path]:
    if path.is_file():
        return [path]
    return sorted(
        p
        for p in path.rglob("*")
        if p.is_file()
        and not SKIP_DIRS.intersection(p.relative_to(path).parts)
        and (not suffixes or p.name.endswith(suffixes))
    )


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__.split("\n\n")[0])
    parser.add_argument("paths", nargs="+", type=Path, help="files or directories to scan")
    parser.add_argument("--suffix", action="append", default=[], help="only files with this ending (repeatable)")
    args = parser.parse_args(argv)

    found: list[str] = []
    for path in args.paths:
        if not path.exists():
            print(f"::error::{path} does not exist", file=sys.stderr)
            return 1
        for file in files_under(path, tuple(args.suffix)):
            try:
                text = file.read_text(encoding="utf-8")
            except UnicodeDecodeError:
                continue  # images and fonts
            found += findings_in(text, str(file))
    for finding in found:
        print(f"::error::{finding}", file=sys.stderr)
    if found:
        print("Real data of an installation never belongs on the website (CONTRIBUTING.md).", file=sys.stderr)
        return 1
    print("privacy scan: no private addresses, host names or e-mail addresses")
    return 0


if __name__ == "__main__":
    sys.exit(main())
