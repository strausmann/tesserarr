<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->pgsql = config('database.connections.pgsql');
});

afterEach(function () {
    config(['database.connections.pgsql' => $this->pgsql]);
    DB::purge('pgsql');
});

it('reports 200 on /up when the database answers', function () {
    $this->get('/up')->assertOk();
});

it('reports 500 on /up when the database is unreachable', function () {
    config([
        'database.connections.pgsql.host' => '127.0.0.1',
        'database.connections.pgsql.port' => closedLocalPort(),
    ]);
    DB::purge('pgsql');

    $this->get('/up')->assertStatus(500);
});
