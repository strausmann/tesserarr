<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Console\Commands;

use App\Privacy\PiiRotation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Moves the personal data to the current keys after a key change
 * (website: admin/privacy). Prints and logs counts only.
 */
final class PiiRotate extends Command
{
    protected $signature = 'tesserarr:pii:rotate
        {--dry-run : count what would change, write nothing}';

    protected $description = 'Re-encrypt personal data with TESSERARR_PII_KEY and recompute the blind index after a key change';

    public function handle(PiiRotation $rotation): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $report = $rotation->run($dryRun);

        foreach ($report as $column => $counts) {
            $verb = str_ends_with($column, '_bidx') ? 'recomputed' : 're-encrypted';
            $this->line(sprintf('%s: %d of %d %s%s', $column, $counts['changed'], $counts['total'], $dryRun ? 'would be ' : '', $verb));
        }

        Log::info('tesserarr:pii:rotate', ['dry_run' => $dryRun, 'counts' => $report]);

        return self::SUCCESS;
    }
}
