<?php

namespace App\Listeners;

use App\Runtime\InstanceRole;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Support\Facades\DB;

/**
 * Makes /up fail with 500 when the central instance cannot reach its
 * database. A satellite has none, so it reports healthy without the check.
 */
final class CheckDatabaseHealth
{
    public function __construct(private readonly InstanceRole $role) {}

    public function handle(DiagnosingHealth $event): void
    {
        if ($this->role !== InstanceRole::Central) {
            return;
        }

        DB::connection()->select('select 1');
    }
}
