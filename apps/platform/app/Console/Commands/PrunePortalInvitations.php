<?php

namespace App\Console\Commands;

use App\Domain\Identity\Application\Retention\PortalInvitationRetentionService;
use App\Models\School;
use App\Support\Observability\MetricsRecorder;
use App\Support\Retention\RetentionHolds;
use App\Support\Retention\RetentionMetrics;
use App\Support\Retention\RetentionPeriod;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;

/**
 * E21.3B (E21.2G I2, docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): ended portal invitations
 * (`identity_account_invitations`) are deleted
 * PORTAL_INVITATION_RETENTION_DAYS (adopted 7) after they ended (accepted,
 * revoked, or expired unaccepted). A usable invitation is never touched.
 *
 * - This is a retention period, not a technical TTL: a held School
 *   (RETENTION_HOLD_SCHOOL_IDS) is counted only.
 * - Staff invitations are a separate, implemented technical TTL
 *   (`platform:staff-account-credentials-prune`, ADR 0059).
 * - Each School is processed in its own context, suspended and closed ones
 *   included. No default: an unset period deletes nothing.
 * - `--dry-run` counts with the same predicate. Counts only in logs.
 */
class PrunePortalInvitations extends Command
{
    protected $signature = 'platform:portal-invitations-prune {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Deletes ended portal invitations PORTAL_INVITATION_RETENTION_DAYS (adopted 7) after they ended (E21.2G I2; nothing while unset).';

    public function handle(PortalInvitationRetentionService $invitations, RetentionHolds $holds, MetricsRecorder $metrics): int
    {
        try {
            $days = RetentionPeriod::days(config('retention.portal_invitation_days'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($days === null) {
            Log::info('retention.portal_invitations_prune.unconfigured');
            $this->info('Portal invitation retention is not configured (PORTAL_INVITATION_RETENTION_DAYS); nothing was deleted.');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $batch = max(1, (int) config('retention.batch_size'));
        $cutoff = now('UTC')->subDays($days);
        $totals = ['eligible' => 0, 'deleted' => 0, 'held' => 0, 'dependency_blocked' => 0, 'errors' => 0];

        School::query()->orderBy('id')->chunk(100, function ($schools) use ($invitations, $holds, $cutoff, $batch, $dryRun, &$totals): void {
            foreach ($schools as $school) {
                foreach ($invitations->prune($school, $cutoff, $batch, $dryRun, $holds->isHeld($school->id)) as $outcome => $count) {
                    $totals[$outcome] += $count;
                }
            }
        });

        RetentionMetrics::record($metrics, RetentionMetrics::PORTAL_INVITATION, $totals);
        Log::info($dryRun ? 'retention.portal_invitations_prune.dry_run' : 'retention.portal_invitations_prune.completed', ['days' => $days, 'counts' => $totals]);

        $n = $dryRun ? $totals['eligible'] - $totals['held'] - $totals['dependency_blocked'] : $totals['deleted'];
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." {$n} ended portal invitation(s) (held: {$totals['held']}, dependency-blocked: {$totals['dependency_blocked']}, errors: {$totals['errors']}).");

        return self::SUCCESS;
    }
}
