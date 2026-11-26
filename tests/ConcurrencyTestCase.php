<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace Tests;

/**
 * Base of tests/Concurrency (DatabaseTruncation). The audit log refuses
 * TRUNCATE (trigger audit_entries_no_truncate), so it is left out of the
 * truncation; its rows do not take part in the races.
 */
abstract class ConcurrencyTestCase extends TestCase
{
    /** @var list<string> */
    protected $exceptTables = ['audit_entries'];
}
