<?php

// SPDX-FileCopyrightText: 2026 Björn Strausmann and Tesserarr contributors
// SPDX-License-Identifier: AGPL-3.0-or-later

declare(strict_types=1);

namespace App\Privacy;

use Illuminate\Database\ConnectionInterface;

/**
 * Moves personal data to the current keys (`tesserarr:pii:rotate`):
 *
 * - every encrypted column is read with all keys and written again with the
 *   current one, if a previous key wrote it;
 * - with TESSERARR_BLIND_INDEX_PREVIOUS_KEY set, every blind index is
 *   computed again from the decrypted address with the current blind index
 *   key, the claims and pending confirmation links included.
 *
 * Row by row in batches of BATCH, each batch in a transaction of its own, so
 * a long run can be interrupted and started again. It reports counts only,
 * never a value. Besides App\Privacy\EmailConfirmation, the one writer of the
 * account address columns: it rewrites the same address under another key.
 */
final class PiiRotation
{
    public const BATCH = 200;

    /** Column => blind index column of the same address (or null). */
    private const COLUMNS = [
        'users' => ['email' => 'email_bidx', 'pending_email' => 'pending_email_bidx', 'pgp_public_key' => null],
        'identities' => ['email' => 'email_bidx'],
    ];

    public function __construct(
        private readonly ConnectionInterface $db,
        private readonly PiiEncrypter $pii,
        private readonly PiiKeys $keys,
    ) {}

    /**
     * @return array<string, array{total: int, changed: int}> per "table.column"
     */
    public function run(bool $dryRun): array
    {
        $reindex = $this->keys->blindIndexPrevious !== null;
        /** @var array<string, array{total: int, changed: int}> $report */
        $report = [];

        foreach (self::COLUMNS as $table => $columns) {
            foreach ($columns as $column => $bidx) {
                $report["{$table}.{$column}"] = ['total' => 0, 'changed' => 0];
                if ($bidx !== null && $reindex) {
                    $report["{$table}.{$bidx}"] = ['total' => 0, 'changed' => 0];
                }
            }

            $this->db->table($table)->select(['id', ...array_keys($columns), ...array_filter($columns)])
                ->orderBy('id')
                ->chunkById(self::BATCH, function ($rows) use ($table, $columns, $reindex, $dryRun, &$report): void {
                    $this->db->transaction(function () use ($rows, $table, $columns, $reindex, $dryRun, &$report): void {
                        foreach ($rows as $read) {
                            // Read again under a row lock: a confirmation that
                            // committed after the batch was read must not be
                            // overwritten with the values it replaced.
                            $row = $this->db->table($table)->where('id', $read->id)->lockForUpdate()
                                ->first(['id', ...array_keys($columns), ...array_filter($columns)]);
                            if ($row === null) {
                                continue;
                            }
                            $update = [];
                            foreach ($columns as $column => $bidx) {
                                $payload = $row->{$column};
                                if (! is_string($payload)) {
                                    continue;
                                }
                                self::count($report, "{$table}.{$column}", false);
                                $plain = $this->pii->decrypt($payload);

                                if (! $this->pii->isCurrent($payload)) {
                                    self::count($report, "{$table}.{$column}", true);
                                    $update[$column] = $this->pii->encrypt($plain);
                                }

                                if ($bidx !== null && $reindex) {
                                    self::count($report, "{$table}.{$bidx}", false);
                                    $new = BlindIndex::of($plain);
                                    if ($row->{$bidx} !== $new) {
                                        self::count($report, "{$table}.{$bidx}", true);
                                        $update[$bidx] = $new;
                                        $this->moveIndex($table, (string) $row->{$bidx}, $new, $dryRun);
                                    }
                                }
                            }

                            if ($update !== [] && ! $dryRun) {
                                $this->db->table($table)->where('id', $row->id)->update($update);
                            }
                        }
                    });
                });
        }

        return $report;
    }

    /**
     * @param  array<string, array{total: int, changed: int}>  $report
     */
    private static function count(array &$report, string $column, bool $changed): void
    {
        $entry = $report[$column] ?? ['total' => 0, 'changed' => 0];
        $changed ? $entry['changed']++ : $entry['total']++;
        $report[$column] = $entry;
    }

    /** The claims and open links of an account address follow its new index. */
    private function moveIndex(string $table, string $old, string $new, bool $dryRun): void
    {
        if ($table !== 'users' || $dryRun || $old === '') {
            return;
        }

        $this->db->table('account_email_claims')->where('bidx', $old)->update(['bidx' => $new]);
        $this->db->table('email_confirmations')->where('email_bidx', $old)->update(['email_bidx' => $new]);
    }
}
