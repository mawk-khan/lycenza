<?php

namespace App\Console\Commands;

use App\Models\ApiIdempotencyKey;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Section 23. NOT scheduled yet (section 23: "do not schedule
 * production pruning yet") -- run manually/ad hoc for now.
 *
 * Iterates Schools one at a time and deletes only THAT School's
 * expired rows through the ordinary RLS-protected runtime connection
 * (TenantContext::withSchool), in bounded batches -- never a single
 * cross-tenant DELETE. This means the command never needs (and never
 * uses) the migration-only `pgsql_admin` connection's elevated
 * privileges, so it carries no risk of accidentally bypassing RLS.
 *
 * A 'processing' row is NEVER pruned here even if `expires_at` has
 * passed -- IdempotencyGuard's own in-flight-timeout reclaim logic
 * owns that row's lifecycle; deleting it out from under a possibly
 * still-genuinely-running request would defeat the in-progress
 * guarantee.
 */
class PruneIdempotencyRecords extends Command
{
    protected $signature = 'platform:idempotency-prune';

    protected $description = 'Deletes expired api_idempotency_keys rows, one School and one bounded batch at a time.';

    public function handle(TenantContext $context): int
    {
        $batchSize = (int) config('idempotency.prune_batch_size');
        $totalDeleted = 0;

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($context, $batchSize, &$totalDeleted): void {
            foreach ($schools as $school) {
                $context->withSchool($school, function () use ($school, $batchSize, &$totalDeleted): void {
                    do {
                        $ids = ApiIdempotencyKey::query()
                            ->where('school_id', $school->id)
                            ->where('status', '!=', 'processing')
                            ->where('expires_at', '<', now())
                            ->limit($batchSize)
                            ->pluck('id');

                        if ($ids->isEmpty()) {
                            break;
                        }

                        $deleted = ApiIdempotencyKey::query()->whereIn('id', $ids)->delete();
                        $totalDeleted += $deleted;

                        Log::info('idempotency.pruned', [
                            'school_id' => $school->id,
                            'deleted' => $deleted,
                        ]);
                    } while ($ids->count() === $batchSize);
                });
            }
        });

        $this->info("Pruned {$totalDeleted} expired idempotency record(s).");

        return self::SUCCESS;
    }
}
