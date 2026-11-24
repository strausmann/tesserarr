<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

namespace App\Runtime;

/**
 * The process a container runs; the argument of docker/entrypoint.sh.
 */
enum ProcessRole: string
{
    case Web = 'web';
    case Worker = 'worker';
    case Scheduler = 'scheduler';
    case Reverb = 'reverb';
    case Satellite = 'satellite';

    /**
     * The instance role this process belongs to; the entrypoint refuses to
     * start it on an instance of the other role.
     */
    public function instanceRole(): InstanceRole
    {
        return $this === self::Satellite ? InstanceRole::Satellite : InstanceRole::Central;
    }
}
