<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\WebhookDelivery;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0C closeout -- webhook delivery-history retention.
 *
 * Deletes TERMINAL webhook deliveries (delivered/failed/abandoned) whose
 * last state change is older than the retention period, one School and
 * one bounded batch at a time, through the ordinary RLS-protected
 * runtime connection (TenantContext::withSchool()) -- exactly the pattern
 * of PruneIdempotencyRecords (docs/architecture/INTEGRATIONS.md
 * "Retention"). Never a cross-tenant DELETE, never pgsql_admin.
 *
 * - Attempt rows are append-only (UPDATE/DELETE revoked from the runtime
 *   role); they are removed only with their delivery, through the
 *   existing ON DELETE CASCADE, never individually.
 * - pending / delivering / retrying deliveries, and any delivery still
 *   holding a processing lease, are never eligible.
 * - The DELETE re-checks status and age itself, so a delivery that an
 *   administrator redelivers (-> pending) between the batch SELECT and
 *   the DELETE is not removed.
 * - School audit events (integrations.webhook_*) and the domain-event
 *   outbox are separate tables and are never touched.
 *
 * Retention comes from config('webhooks.delivery_retention_days'), which
 * has NO default: no retention period has been decided
 * ([LEGAL REVIEW REQUIRED], docs/security/DATA-CLASSIFICATION.md). While
 * it is unset this command deletes nothing. `--days` overrides it for an
 * explicit manual run; `--dry-run` only counts. Safe to re-run.
 */
class PruneWebhookDeliveries extends Command
{
    protected $signature = 'platform:webhook-deliveries-prune
        {--days= : Retention in whole days (overrides WEBHOOKS_DELIVERY_RETENTION_DAYS for this run)}
        {--dry-run : Count what would be pruned without deleting anything}';

    protected $description = 'Deletes terminal webhook deliveries (and, by cascade, their attempts) older than the configured retention period, per School in bounded batches.';

    public function handle(TenantContext $context): int
    {
        $rawDays = $this->option('days') ?? config('webhooks.delivery_retention_days');

        if ($rawDays === null || $rawDays === '') {
            Log::info('webhooks.deliveries_prune.skipped', ['reason' => 'retention_not_configured']);
            $this->info('Webhook delivery retention is not configured (WEBHOOKS_DELIVERY_RETENTION_DAYS); nothing pruned.');

            return self::SUCCESS;
        }

        if (! is_numeric($rawDays) || (int) $rawDays != $rawDays || (int) $rawDays < 1) {
            $this->error('Retention must be a whole number of days, at least 1.');

            return self::FAILURE;
        }

        $days = (int) $rawDays;
        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) config('webhooks.prune_batch_size'));
        $cutoff = Carbon::now()->subDays($days);
        $total = 0;

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($context, $cutoff, $batchSize, $dryRun, &$total): void {
            foreach ($schools as $school) {
                $affected = $context->withSchool($school, function () use ($school, $cutoff, $batchSize, $dryRun): int {
                    if ($dryRun) {
                        return $this->eligible($school->id, $cutoff)->count();
                    }

                    $deletedForSchool = 0;

                    do {
                        $ids = $this->eligible($school->id, $cutoff)->orderBy('id')->limit($batchSize)->pluck('id');

                        if ($ids->isEmpty()) {
                            break;
                        }

                        // Predicate re-applied in the DELETE itself (never
                        // check-then-act): a concurrent redelivery that moved
                        // a row back to `pending` makes it ineligible here.
                        $deleted = $this->eligible($school->id, $cutoff)->whereIn('id', $ids)->delete();
                        $deletedForSchool += $deleted;
                    } while ($ids->count() === $batchSize);

                    return $deletedForSchool;
                });

                if ($affected > 0) {
                    Log::info($dryRun ? 'webhooks.deliveries_prune.would_prune' : 'webhooks.deliveries_prune.pruned', [
                        'school_id' => $school->id,
                        'deliveries' => $affected,
                    ]);
                }

                $total += $affected;
            }
        });

        Log::info('webhooks.deliveries_prune.completed', [
            'dry_run' => $dryRun,
            'retention_days' => $days,
            'deliveries' => $total,
        ]);

        $this->info($dryRun
            ? "Dry run: {$total} terminal webhook deliver(ies) older than {$days} day(s) would be pruned."
            : "Pruned {$total} terminal webhook deliver(ies) older than {$days} day(s).");

        return self::SUCCESS;
    }

    /**
     * @return Builder<WebhookDelivery>
     */
    private function eligible(string $schoolId, Carbon $cutoff): Builder
    {
        return WebhookDelivery::query()
            ->where('school_id', $schoolId)
            ->whereIn('status', WebhookDelivery::TERMINAL_STATUSES)
            ->where(fn (Builder $query) => $query
                ->whereNull('processing_lease_expires_at')
                ->orWhere('processing_lease_expires_at', '<', Carbon::now()))
            ->where('updated_at', '<', $cutoff);
    }
}
