<?php

namespace App\Console\Commands;

use App\Support\Retention\RetentionHolds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E21-RH.2 (ADR 0066 §6.1 transition): OPERATOR maintenance that records
 * the configured School holds (RETENTION_HOLD_SCHOOL_IDS) in the database
 * hold mirror the HRX purge functions enforce, and records their release.
 *
 * It runs on the migration/owner connection (operator-console process,
 * ADR 0021), never from the scheduler: the scheduled retention identity can
 * only READ the mirror, so it can neither place nor release a hold. A
 * destructive scheduled run refuses while a configured hold is not recorded
 * (fail closed), and a recorded hold stays enforced until this command
 * records its release -- run it after every change to
 * RETENTION_HOLD_SCHOOL_IDS.
 *
 * Counts only; no School identifier is printed or logged.
 */
class SyncRetentionHolds extends Command
{
    protected $signature = 'platform:retention-holds-sync';

    protected $description = 'Records the configured School retention holds in the database mirror (operator maintenance, migration connection; E21-RH.2).';

    public function handle(RetentionHolds $holds): int
    {
        $unknown = $holds->synchronize();
        $configured = count($holds->heldSchoolIds());
        Log::info('retention.holds_synchronized', ['configured' => $configured, 'unknown' => count($unknown)]);

        if ($unknown !== []) {
            $this->error(count($unknown).' configured hold(s) name no existing School and were not recorded; destructive retention keeps refusing until RETENTION_HOLD_SCHOOL_IDS is corrected.');

            return self::FAILURE;
        }

        $this->info("Recorded {$configured} configured School hold(s) in the database; released any no longer configured.");

        return self::SUCCESS;
    }
}
