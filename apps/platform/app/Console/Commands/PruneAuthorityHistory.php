<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionPeriod;
use App\Support\Tenancy\SchoolTimezone;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D6 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): authority-bearing history.
 *
 * Expires authority whose end is more than AUTHORITY_HISTORY_RETENTION_YEARS
 * (adopted: 7) calendar years ago, through the narrow database retention
 * functions. The runtime role still has no DELETE on any of these tables.
 * It runs in dependency order, and the database skips any row a younger
 * record still references (longest period wins).
 *
 * Per School, in its own tenant context; held Schools are skipped:
 * 1. revoked School role grants (`revoked_at`);
 * 2. TeachingAssignments whose last effective day (`ends_on`) is that old,
 *    on the School-local date. Open and future rows are never eligible;
 * 3. finished elevations (`ended_at`, else `expires_at`) no School audit
 *    event references any more (`platform:audit-prune` runs first).
 * 4. (E21.3E, E21.2G I3) ended API client credentials: their authority
 *    ended at LEAST(revoked_at, expires_at); a revocation is final and an
 *    expiry is never extended, so a current credential is never eligible.
 *
 * Platform-wide (RETENTION_HOLD_PLATFORM holds them):
 * 5. revoked Group grants no elevation references any more;
 * 6. revoked platform role grants.
 *
 * Not touched here, by design (determination E21-D6):
 * - the Employee↔User link history, which lives in the audit ledger
 *   (E21-D1);
 * - LMS owner and Section audience, which are kept with their parent
 *   resource and never removed while it survives.
 *
 * Active authority is never eligible, and authorization never reads a
 * revoked or ended row. There is no default: unset deletes nothing.
 * `--dry-run` counts only.
 */
class PruneAuthorityHistory extends Command
{
    protected $signature = 'platform:authority-history-prune
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires revoked/ended authority older than AUTHORITY_HISTORY_RETENTION_YEARS (E21-D6; nothing while unset).';

    public function handle(RetentionExpiry $expiry): int
    {
        try {
            $years = RetentionPeriod::years(config('retention.authority_history_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.authority_prune.unconfigured');
            $this->info('Authority-history retention is not configured (AUTHORITY_HISTORY_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = RetentionPeriod::yearsBeforeNow($years);
        $batch = max(1, (int) config('retention.batch_size'));
        $totals = [];

        $add = function (string $category, array $result) use (&$totals): void {
            foreach ($result as $k => $n) {
                $totals[$category][$k] = ($totals[$category][$k] ?? 0) + $n;
            }
        };

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($expiry, $cutoff, $years, $batch, $dryRun, $add): void {
            foreach ($schools as $school) {
                $localCutoff = CarbonImmutable::now(SchoolTimezone::resolve($school))->subYearsNoOverflow($years);

                $add(RetentionExpiry::SCHOOL_ROLE_GRANT, $expiry->forSchool(RetentionExpiry::SCHOOL_ROLE_GRANT, $school, $cutoff, $batch, $dryRun));
                $add(RetentionExpiry::TEACHING_ASSIGNMENT, $expiry->forSchool(RetentionExpiry::TEACHING_ASSIGNMENT, $school, $localCutoff, $batch, $dryRun));
                $add(RetentionExpiry::SCHOOL_ELEVATION, $expiry->forSchool(RetentionExpiry::SCHOOL_ELEVATION, $school, $cutoff, $batch, $dryRun));
                // E21.3E (E21.2G I3): ended API client credentials, LEAST(revoked_at, expires_at).
                $add(RetentionExpiry::SCHOOL_API_CREDENTIAL, $expiry->forSchool(RetentionExpiry::SCHOOL_API_CREDENTIAL, $school, $cutoff, $batch, $dryRun));
            }
        });

        $add(RetentionExpiry::GROUP_ROLE_GRANT, $expiry->forPlatform(RetentionExpiry::GROUP_ROLE_GRANT, $cutoff, $batch, $dryRun));
        $add(RetentionExpiry::PLATFORM_ROLE_GRANT, $expiry->forPlatform(RetentionExpiry::PLATFORM_ROLE_GRANT, $cutoff, $batch, $dryRun));

        Log::info($dryRun ? 'retention.authority_prune.dry_run' : 'retention.authority_prune.completed', ['retention_years' => $years, 'categories' => $totals]);

        foreach ($totals as $category => $r) {
            $count = $dryRun ? ($r['eligible'] ?? 0) - ($r['held'] ?? 0) : ($r['deleted'] ?? 0);
            $this->line(($dryRun ? 'Dry run: would delete' : 'Deleted')." {$count} {$category}; held: ".($r['held'] ?? 0).'.');
        }

        return self::SUCCESS;
    }
}
