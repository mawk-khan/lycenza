<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\WebhookDelivery;
use App\Support\Retention\RetentionAnchors;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * Phase 0C closeout + E21-D4 (docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): webhook delivery-history
 * retention.
 *
 * Deletes TERMINAL webhook deliveries whose last state change (the terminal
 * disposition) is older than their period:
 * - `delivered`: WEBHOOKS_DELIVERY_RETENTION_DAYS (adopted: 30);
 * - `failed` / `abandoned`: WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS
 *   (adopted: 90).
 *
 * Neither period has a default. Each status group is pruned only when its
 * own period is set, so an unset period deletes nothing.
 *
 * It works one School and one bounded batch at a time, through the ordinary
 * RLS-protected runtime connection (TenantContext::withSchool()), never a
 * cross-tenant DELETE and never pgsql_admin.
 * - A School on the E21 hold list (RETENTION_HOLD_SCHOOL_IDS) is skipped.
 * - Attempt rows are append-only (UPDATE/DELETE revoked from the runtime
 *   role). They are removed only with their delivery, through the existing
 *   ON DELETE CASCADE.
 * - pending / delivering / retrying deliveries, and any delivery still
 *   holding a processing lease, are never eligible.
 * - The DELETE re-checks status and age itself, so a delivery that an
 *   administrator redelivers (-> pending) between the batch SELECT and the
 *   DELETE is not removed.
 * - School audit events and the domain-event outbox are separate tables
 *   (the outbox has its own `platform:outbox-prune`).
 *
 * `--days` / `--failed-days` override the periods for an explicit manual
 * run; `--dry-run` only counts. Safe to re-run.
 */
class PruneWebhookDeliveries extends Command
{
    protected $signature = 'platform:webhook-deliveries-prune
        {--days= : Retention in whole days for delivered rows (overrides WEBHOOKS_DELIVERY_RETENTION_DAYS for this run)}
        {--failed-days= : Retention in whole days for failed/abandoned rows (overrides WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS for this run)}
        {--dry-run : Count what would be pruned without deleting anything}';

    protected $description = 'Deletes terminal webhook deliveries (and, by cascade, their attempts) older than their configured retention period, per School in bounded batches.';

    public function handle(TenantContext $context, RetentionHolds $holds, RetentionExpiry $retention): int
    {
        try {
            $periods = array_filter([
                'delivered' => RetentionPeriod::days($this->option('days') ?? config('webhooks.delivery_retention_days')),
                'failed' => RetentionPeriod::days($this->option('failed-days') ?? config('webhooks.failed_delivery_retention_days')),
            ]);
        } catch (InvalidArgumentException) {
            $this->error('Retention must be a whole number of days, at least 1.');

            return self::FAILURE;
        }

        if ($periods === []) {
            Log::info('webhooks.deliveries_prune.skipped', ['reason' => 'retention_not_configured']);
            $this->info('Webhook delivery retention is not configured (WEBHOOKS_DELIVERY_RETENTION_DAYS / WEBHOOKS_FAILED_DELIVERY_RETENTION_DAYS); nothing pruned.');

            return self::SUCCESS;
        }

        $groups = [];
        foreach ($periods as $group => $days) {
            $groups[$group] = [
                'statuses' => $group === 'delivered' ? ['delivered'] : array_values(array_diff(WebhookDelivery::TERMINAL_STATUSES, ['delivered'])),
                'cutoff' => Carbon::now()->subDays($days),
            ];
        }

        $dryRun = (bool) $this->option('dry-run');
        $batchSize = max(1, (int) config('webhooks.prune_batch_size'));
        $total = 0;
        $heldSchools = 0;

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($context, $holds, $retention, $groups, $batchSize, $dryRun, &$total, &$heldSchools): void {
            foreach ($schools as $school) {
                if ($holds->isHeld($school->id)) {
                    $heldSchools++;

                    continue;
                }

                // E21-RH.6: as the retention identity (its delete guard re-checks the School's hold in the database).
                $affected = $retention->retained('webhook_delivery', $dryRun, $school->id, ['deleted' => 0, 'errors' => 0], fn (): array => ['errors' => 0, 'deleted' => $context->withSchool($school, function () use ($school, $groups, $batchSize, $dryRun): int {
                    $deletedForSchool = 0;

                    foreach ($groups as $group) {
                        $eligible = fn () => $this->eligible($school->id, $group['statuses'], $group['cutoff']);

                        if ($dryRun) {
                            $deletedForSchool += $eligible()->count();

                            continue;
                        }

                        do {
                            $ids = $eligible()->orderBy('id')->limit($batchSize)->pluck('id');

                            if ($ids->isEmpty()) {
                                break;
                            }

                            // Predicate re-applied in the DELETE itself (never
                            // check-then-act): a concurrent redelivery that moved
                            // a row back to `pending` makes it ineligible here.
                            $deletedForSchool += $eligible()->whereIn('id', $ids)->delete();
                        } while ($ids->count() === $batchSize);
                    }

                    return $deletedForSchool;
                })], recordedBefore: RetentionExpiry::recordedBefore(...array_column($groups, 'cutoff')))['deleted'];

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
            'retention_days' => $periods,
            'deliveries' => $total,
            'held_schools' => $heldSchools,
        ]);

        $this->info($dryRun
            ? "Dry run: {$total} terminal webhook deliver(ies) would be pruned; {$heldSchools} School(s) held."
            : "Pruned {$total} terminal webhook deliver(ies); {$heldSchools} School(s) held.");

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $statuses
     * @return Builder<WebhookDelivery>
     */
    private function eligible(string $schoolId, array $statuses, Carbon $cutoff): Builder
    {
        // E21-RH.7: only rows the database recorded (and last changed) before the declared cutoff.
        return RetentionAnchors::recordedBefore(WebhookDelivery::query(), 'webhook_deliveries')
            ->where('school_id', $schoolId)
            ->whereIn('status', $statuses)
            ->where(fn (Builder $query) => $query
                ->whereNull('processing_lease_expires_at')
                ->orWhere('processing_lease_expires_at', '<', Carbon::now()))
            ->where('updated_at', '<', $cutoff);
    }
}
