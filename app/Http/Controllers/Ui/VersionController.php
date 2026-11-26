<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Http\Controllers\Ui;

use App\Http\Controllers\Controller;
use App\Http\Middleware\AddVersionHeader;
use Illuminate\Http\JsonResponse;

/**
 * The running version, for the check an open tab makes now and then
 * (resources/js/lib/updates.svelte.ts). The answer carries it in the header
 * X-Tesserarr-Version as well, like every answer.
 */
final class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['version' => AddVersionHeader::running()]);
    }
}
