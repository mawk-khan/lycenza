<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D1 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): audit retention.
 *
 * School and platform audit events are deleted AUDIT_RETENTION_YEARS
 * (adopted: 7) calendar years after their `occurred_at`. The deletion
 * happens through the narrow database retention functions (RetentionExpiry):
 * the runtime role still has no DELETE on either ledger, and the database
 * refuses any cutoff younger than seven years.
 *
 * - Every School is processed in its own tenant context, suspended ones
 *   included.
 * - A held School (RETENTION_HOLD_SCHOOL_IDS) and, for the platform ledger,
 *   RETENTION_HOLD_PLATFORM delete nothing.
 * - There is no default: unset deletes nothing.
 * - `--dry-run` counts only. Bounded batches; safe to re-run.
 * - The audit ledgers have no hash chain or sequence link, so expiry is
 *   a plain deletion.
 */
class PruneAuditEvents extends Command
{
    protected $signature = 'platform:audit-prune
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires School and platform audit events older than AUDIT_RETENTION_YEARS (E21-D1; nothing while unset).';

    public function handle(RetentionExpiry $expiry): int
    {
        try {
            $years = RetentionPeriod::years(config('retention.audit_years'));
            $caseYears = RetentionPeriod::years(config('retention.erasure_case_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.audit_prune.unconfigured');
            $this->info('Audit retention is not configured (AUDIT_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = RetentionPeriod::yearsBeforeNow($years);
        $batch = max(1, (int) config('retention.batch_size'));
        $school = ['eligible' => 0, 'deleted' => 0, 'held' => 0];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($expiry, $cutoff, $batch, $dryRun, &$school): void {
            foreach ($schools as $one) {
                foreach ($expiry->forSchool(RetentionExpiry::SCHOOL_AUDIT, $one, $cutoff, $batch, $dryRun) as $k => $n) {
                    $school[$k] += $n;
                }
            }
        });

        $platform = $expiry->forPlatform(RetentionExpiry::PLATFORM_AUDIT, $cutoff, $batch, $dryRun);

        // E21.2F (E21-D10): closed erasure cases are compliance evidence too, on
        // their own adopted period (no default: unset keeps them).
        $cases = $caseYears === null ? null : $expiry->forPlatform(RetentionExpiry::ERASURE_CASE, RetentionPeriod::yearsBeforeNow($caseYears), $batch, $dryRun);

        Log::info($dryRun ? 'retention.audit_prune.dry_run' : 'retention.audit_prune.completed', [
            'retention_years' => $years, 'school' => $school, 'platform' => $platform, 'erasure_cases' => $cases,
        ]);
        $verb = $dryRun ? 'Dry run: would delete' : 'Deleted';
        $this->info("{$verb} {$this->n($school, $dryRun)} School and {$this->n($platform, $dryRun)} platform audit event(s); held: {$school['held']} School, {$platform['held']} platform.");
        if ($cases !== null) {
            $this->info("{$verb} {$this->n($cases, $dryRun)} closed erasure case(s); held: {$cases['held']}.");
        }

        return self::SUCCESS;
    }

    /** @param  array{eligible: int, deleted: int, held: int}  $r */
    private function n(array $r, bool $dryRun): int
    {
        return $dryRun ? $r['eligible'] - $r['held'] : $r['deleted'];
    }
}
