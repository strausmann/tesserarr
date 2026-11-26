<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0011: Design system "Werkstatt"

- **Status:** superseded
- **Date:** 2026-08-14
- **Supersedes:** none
- **Superseded by:** [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md)
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0006](0006-ui-gate-as-a-hard-gate.md)

This record is kept as it was decided for the Next interface of the Go version (tag `archive/go-0.0.2`). The Laravel
interface follows the design line of Labelyard and BambuBridge instead
([ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md)).

## Context

The Next interface needed a named design system, so that every component used one vocabulary of colours, spacing and
type instead of its own values, and so that contrast rules were written down where a component author would meet them.

## Decision

A design system "Werkstatt" with its own token set (`--color-*`, `--space-*`, type) and four binding rules:

1. **The accent is for actions only.** State (running, attention, error) never speaks in the accent colour, and an
   action never in a state colour; the dangerous action is the exception and uses the danger colour.
2. **Input fields use their own border token**, which reaches the 3:1 contrast that WCAG 1.4.11 requires for controls;
   the general border colour does not.
3. **The focus ring keeps an outline offset above 0**: only on the surrounding background does it reach 3:1.
4. **Exactly one card in the whole system**, reserved for the once-shown token; every other separation uses hairlines
   and white space.

## Consequences

- A consistent interface with contrast rules that were measured, not guessed.
- A design vocabulary of its own to maintain, next to the one of the author's other projects.

## History

Superseded on 2026-11-24 by [ADR-0012](0012-laravel-app-one-image-central-and-satellite-roles.md): one design line
across Labelyard, BambuBridge and Tesserarr instead of a design system per project. The contrast rules (points 2 and 3)
stay good practice and are checked by the accessibility scan of the interface gate.
