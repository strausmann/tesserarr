<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Settings;

/** Where the value of a setting comes from, the strongest first. */
enum SettingSource: string
{
    /** An environment variable, for emergencies: the field cannot be edited. */
    case Environment = 'environment';
    case Stored = 'stored';
    case Default = 'default';
}
