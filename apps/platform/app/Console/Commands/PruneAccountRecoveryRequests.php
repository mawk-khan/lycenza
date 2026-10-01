<?php

namespace App\Console\Commands;

use App\Domain\Identity\Infrastructure\AccountRecoveryRequest;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0O.10A (ADR 0056 section 13): recovery credentials are technical,
 * short-lived data -- a row is deleted 24 hours after it ended (consumed,
 * invalidated or expired). Bounded and indexed. The security audit
 * (`platform_audit_events`) is separate and untouched; no legal retention
 * period is invented here.
 */
class PruneAccountRecoveryRequests extends Command
{
    protected $signature = 'platform:account-recovery-prune {--batch=5000 : Maximum rows per run} {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Delete password-recovery credentials 24 hours after they ended (ADR 0056).';

    public function handle(SchedulerHeartbeatRecorder $heartbeats): int
    {
        $cutoff = now()->subHours((int) config('account_recovery.prune_after_hours'));

        $ended = fn () => AccountRecoveryRequest::query()
            ->where(fn ($q) => $q->where('expires_at', '<', $cutoff)
                ->orWhere('consumed_at', '<', $cutoff)
                ->orWhere('invalidated_at', '<', $cutoff));

        // E21.2G: expired one-time security secrets are exempt from retention
        // holds by design (keeping them is the risk; the security audit is
        // the held record). `--dry-run` counts with the same predicate.
        if ($this->option('dry-run')) {
            $this->info("Dry run: would delete {$ended()->count()} ended recovery credential(s).");

            return self::SUCCESS;
        }

        $ids = $ended()->limit(max(1, (int) $this->option('batch')))->pluck('id');

        $deleted = $ids->isEmpty() ? 0 : AccountRecoveryRequest::query()->whereIn('id', $ids)->delete();

        $heartbeats->recordSuccess('account-recovery-prune');
        Log::info('platform.account_recovery_prune.completed', ['deleted' => $deleted]);
        $this->info("Deleted {$deleted} ended recovery credential(s).");

        return self::SUCCESS;
    }
}
