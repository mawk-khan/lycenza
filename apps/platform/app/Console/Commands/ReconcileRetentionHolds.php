<?php

namespace App\Console\Commands;

use App\Support\Audit\AuditRecorder;
use App\Support\Retention\RetentionHolds;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * E21-RH.3 (ADR 0066 §6.1 transition): ADD-ONLY reconciliation of the
 * transitional configuration into the authoritative database holds. Every
 * School named in RETENTION_HOLD_SCHOOL_IDS that is not actively held, and
 * the platform when RETENTION_HOLD_PLATFORM is set, gets a hold
 * (`configuration_transition`, placed via `configuration_reconciliation`).
 * It NEVER releases a hold: a value removed from configuration stays held
 * until `platform:retention-hold-release`. Operator console only
 * (migration/owner connection). Until it has run, a destructive run of a
 * migrated retention function refuses (fail closed).
 */
class ReconcileRetentionHolds extends Command
{
    protected $signature = 'platform:retention-holds-reconcile';

    protected $description = 'Adds the configured retention holds to the authoritative database state; never releases one (E21-RH.3; operator only, audited).';

    public function handle(RetentionHolds $holds, AuditRecorder $audit): int
    {
        $result = $holds->reconcileConfiguration();
        Log::info('retention.holds_reconciled', ['placed' => $result['placed'], 'unknown' => count($result['unknown'])]);
        if ($result['placed'] > 0) {
            $audit->platform('platform.retention_hold.reconciled', metadata: ['placed' => $result['placed']]);
        }

        if ($result['unknown'] !== []) {
            $this->error(count($result['unknown']).' configured hold(s) name no existing School and were not placed; destructive retention keeps refusing until RETENTION_HOLD_SCHOOL_IDS is corrected.');

            return self::FAILURE;
        }

        $this->info("Placed {$result['placed']} hold(s) from configuration; released none (release is explicit only).");

        return self::SUCCESS;
    }
}
