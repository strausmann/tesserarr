<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0006: The interface check as a hard gate

- **Status:** accepted
- **Date:** 2026-08-13
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0011](0011-design-system-werkstatt.md), [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md)

## Context

Most changes to Tesserarr will not touch the interface, and still they can break it: a changed API answer, a renamed
field, a server error behind a page. Unit tests of the core do not see that. A check that only runs when interface files
change would miss exactly the dangerous case. Pixel comparisons, on the other hand, turn red on every font or library
update and are soon ignored.

## Decision

1. The interface is checked in a real browser (Playwright, Chromium) on **every** pull request, not only on changes to
   interface files.
2. A fixed list of views is rendered at three widths (360, 768 and 1440 pixels, phone, tablet, desktop) and in both
   themes (light and dark).
3. Each of these points blocks on its own: the page renders; **no** console error; no failed request; **no** horizontal
   scrolling at any width; a visible keyboard focus; sufficient contrast.
4. **No pixel comparison as a gate.** Screenshots are kept as artefacts of every run; they inform and block nothing.

## Consequences

- A change of the core that breaks the interface without touching an interface file is caught, because the run hangs
  on every pull request.
- Every point is measurable (console, network, scroll width, contrast); nobody argues whether a failure is fair.
- Without pixel comparison the gate stays credible over time.
- Every pull request pays for a browser run, also one that changes only the backend.
- Purely visual regressions (a wrong colour, a shifted element) pass the gate; it guards function, not design. That is
  a deliberate limit.
- The list of views can go stale: a new view has to be added to it by the task that builds it.

**Revisit** when the run time per pull request becomes unbearable, or when visual regressions keep slipping through.

## History

Recorded for the Go version (tag `archive/go-0.0.2`) and carried over unchanged. The Laravel version implements it in
`e2e/ui-gate.spec.ts` and adds a fourth size with an open on-screen keyboard and an accessibility scan (axe).
