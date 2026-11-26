<!--
SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
SPDX-License-Identifier: AGPL-3.0-or-later
-->

# ADR-0009: Two separate log streams

- **Status:** accepted
- **Date:** 2026-08-13, reviewed 2026-11-24
- **Supersedes:** none
- **Superseded by:** none
- **Revises:** none
- **Revised by:** none
- **Related:** [ADR-0013](0013-satellite-pull-model.md)

## Context

Tesserarr has to answer two different questions: who sent which request (for the security layer in front of it, such as
CrowdSec or fail2ban), and what did the service decide and do (for the operator). Mixed into one stream, both become
useless.

## Decision

1. An **access log in the nginx combined format**, unchanged, because ready-made parsers and rules build on it.
2. A separate **audit log** with levels for what the service decided and did.
3. The access log carries the **real client address**, not the one of the proxy in front (`TESSERARR_TRUSTED_PROXIES`);
   otherwise an attack makes the security layer block the proxy and with it every user.
4. **Refusals have their own level.** Four causes look the same from outside: not granted, quota used up, title not
   found, name not buildable.
5. **The rule against repeated sign-in failures excludes the token paths.** A misconfigured arr app produces dozens of
   refusals in minutes without any attack, and the usual counter-measure (a challenge in the browser) cannot be
   answered by a machine. On the session path the rule stays active.
6. **No secret in either stream, not even shortened.** Error texts of other systems are classified, not passed on; they
   regularly contain exactly the values that must not appear.
7. **A satellite writes no audit log.** It reports the results of its jobs to the central instance, which records them.
   Its own output is the access log and the container log of its process.

## Consequences

- Standard tools read the access log without a custom parser.
- The operator reads decisions without wading through requests.
- The audit trail of everything a satellite did lies in one place, the central instance.

## History

Recorded for the Go version (tag `archive/go-0.0.2`). Reviewed for the satellite of the Laravel version: point 7 added.
