<?php

namespace App\Console\Commands;

use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D4 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): processed domain-event outbox retention.
 *
 * Deletes outbox rows that EVERY consumer has acknowledged (`processed_at`
 * set) longer ago than OUTBOX_RETENTION_DAYS (adopted: 30). Their consumer
 * receipts are deleted in the same transaction. There is no default:
 * unset deletes nothing.
 *
 * Never eligible:
 * - pending, failed or unacknowledged rows (the reconciler's and the
 *   operator's work);
 * - a row whose event still has a webhook delivery that is not
 *   `delivered`. A retry or an administrator's redelivery reads the
 *   payload from here, so the row lives until those deliveries are pruned
 *   by their own (longer) period.
 *
 * School rows are pruned inside that School's TenantContext, so the
 * webhook check sees the School's deliveries under RLS. A held School
 * (RETENTION_HOLD_SCHOOL_IDS) is skipped. School-less rows are pruned
 * without context: the webhook fanout never creates a delivery for a
 * School-less event (WebhookFanoutConsumer).
 *
 * Bounded batches. Every DELETE re-applies its predicate, so it is
 * retry-safe. Counts only in logs; `--dry-run` only counts.
 */
class PruneOutboxEvents extends Command
{
    protected $signature = 'platform:outbox-prune
        {--dry-run : Count what would be pruned without deleting anything}';

    protected $description = 'Deletes processed domain-event outbox rows (and their consumer receipts) older than OUTBOX_RETENTION_DAYS (E21-D4; nothing while unset).';

    public function handle(TenantContext $context, RetentionHolds $holds): int
    {
        try {
            $days = RetentionPeriod::days(config('retention.outbox_days'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($days === null) {
            Log::info('retention.outbox_prune.unconfigured');
            $this->info('Outbox retention is not configured (OUTBOX_RETENTION_DAYS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);
        $batch = max(1, (int) config('retention.batch_size'));
        $schoolRows = 0;
        $heldSchools = 0;

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($context, $holds, $cutoff, $batch, $dryRun, &$schoolRows, &$heldSchools): void {
            foreach ($schools as $school) {
                if ($holds->isHeld($school->id)) {
                    $heldSchools++;

                    continue;
                }

                $schoolRows += $context->withSchool($school, fn () => $this->prune(fn () => $this->eligible($cutoff)->where('school_id', $school->id), $batch, $dryRun));
            }
        });

        $platformRows = $this->prune(fn () => $this->eligible($cutoff)->whereNull('school_id'), $batch, $dryRun);

        Log::info($dryRun ? 'retention.outbox_prune.dry_run' : 'retention.outbox_prune.completed', [
            'retention_days' => $days,
            'school_rows' => $schoolRows,
            'platform_rows' => $platformRows,
            'held_schools' => $heldSchools,
        ]);
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." {$schoolRows} School and {$platformRows} platform outbox row(s); {$heldSchools} School(s) held.");

        return self::SUCCESS;
    }

    /** @param  callable(): Builder<DomainEventOutbox>  $eligible */
    private function prune(callable $eligible, int $batch, bool $dryRun): int
    {
        if ($dryRun) {
            return $eligible()->count();
        }

        $deleted = 0;

        do {
            $ids = $eligible()->orderBy('id')->limit($batch)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::transaction(function () use ($eligible, $ids): int {
                $rows = $eligible()->whereIn('id', $ids)->lockForUpdate()->pluck('id')->all();
                DB::table('event_consumer_receipts')->whereIn('event_id', $rows)->delete();

                return DomainEventOutbox::query()->whereIn('id', $rows)->delete();
            });
        } while (count($ids) === $batch);

        return $deleted;
    }

    /** @return Builder<DomainEventOutbox> */
    private function eligible(Carbon $cutoff): Builder
    {
        return DomainEventOutbox::query()
            ->where('status', 'dispatched')
            ->whereNotNull('processed_at')
            ->where('processed_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('webhook_deliveries')
                ->whereColumn('webhook_deliveries.event_id', 'domain_event_outbox.id')
                ->where('webhook_deliveries.status', '!=', 'delivered'));
    }
}
