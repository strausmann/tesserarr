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
 * (resources/js/lib/updates.svelte.ts). The answer carries the build in the
 * header X-Tesserarr-Version, like every answer. Registered without the
 * `web` group (App\Runtime\RoleBoot): it never touches a session.
 */
final class VersionController extends Controller
{
    public function __invoke(): JsonResponse
    {
        return response()->json(['version' => AddVersionHeader::running()]);
    }
}
