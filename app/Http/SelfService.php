<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http;

use Attribute;

/**
 * Marks a controller (or one action) whose routes need a signed-in account
 * but no permission: the own account, the own settings, the start page that
 * sends each audience to its home (pre-flight K9). Everything else goes
 * through the Authorizer; tests/Feature/Security/AuthorizerCallersTest.php
 * fails for a route that does neither.
 */
#[Attribute(Attribute::TARGET_CLASS | Attribute::TARGET_METHOD)]
final class SelfService {}
