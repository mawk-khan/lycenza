<?php

namespace App\Console\Commands;

use App\Support\Retention\RetentionExpiry;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21-D2 (docs/security/E21-RETENTION-DETERMINATION.md, project-adopted,
 * pending legal ratification): released suppression retention.
 *
 * A RELEASED suppression is deleted MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS
 * (adopted: 1) calendar year after its `released_at`, through the narrow
 * database retention function. The runtime role still has no DELETE on
 * `email_suppressions`.
 * - An active suppression is never eligible, whatever its age. Releasing
 *   stays `platform:mail-suppression-release`'s job (ADR 0055).
 * - The provider event a deleted suppression referenced is no longer
 *   pinned. `platform:email-prune` deletes it on its next run once it is
 *   past MAIL_RETENTION_DAYS (E21.2A L3).
 * - Suppressions belong to no School, so RETENTION_HOLD_PLATFORM holds
 *   them.
 * - There is no default: unset deletes nothing. `--dry-run` counts only.
 */
class PruneReleasedEmailSuppressions extends Command
{
    protected $signature = 'platform:email-suppressions-prune
        {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Expires released email suppressions older than MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS (E21-D2; never an active one; nothing while unset).';

    public function handle(RetentionExpiry $expiry): int
    {
        try {
            $years = RetentionPeriod::years(config('retention.released_suppression_years'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($years === null) {
            Log::info('retention.suppression_prune.unconfigured');
            $this->info('Released-suppression retention is not configured (MAIL_RELEASED_SUPPRESSION_RETENTION_YEARS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $result = $expiry->forPlatform(RetentionExpiry::RELEASED_SUPPRESSION, RetentionPeriod::yearsBeforeNow($years), max(1, (int) config('retention.batch_size')), $dryRun);

        Log::info($dryRun ? 'retention.suppression_prune.dry_run' : 'retention.suppression_prune.completed', ['retention_years' => $years] + $result);
        $count = $dryRun ? $result['eligible'] - $result['held'] : $result['deleted'];
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." {$count} released suppression(s); held: {$result['held']}.");

        return self::SUCCESS;
    }
}
