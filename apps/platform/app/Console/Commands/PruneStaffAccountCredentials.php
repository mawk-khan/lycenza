<?php

namespace App\Console\Commands;

use App\Domain\Identity\Infrastructure\AccountActivationCredential;
use App\Domain\Identity\Infrastructure\StaffAccountInvitation;
use App\Models\School;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0O.12B (ADR 0059 section 21): technical cleanup -- never a legal
 * retention period (that stays ADR 0058 E21).
 * - bootstrap activation credentials: deleted 24 hours after they ended
 *   (consumed, invalidated or expired) -- identity-level, no School;
 * - staff account invitations (with their role rows): deleted 7 days after
 *   they ended (accepted, revoked or expired), School by School inside that
 *   School's context (forced RLS).
 * Bounded per run. The audit ledgers are untouched: they are the record.
 */
class PruneStaffAccountCredentials extends Command
{
    public const ACTIVATION_AFTER_HOURS = 24;

    public const INVITATION_AFTER_DAYS = 7;

    protected $signature = 'platform:staff-account-credentials-prune {--batch=5000 : Maximum rows per kind per run} {--dry-run : Count what would be deleted without deleting anything}';

    protected $description = 'Delete ended bootstrap activation credentials (after 24 h) and ended staff invitations (after 7 days) (ADR 0059).';

    public function handle(SchedulerHeartbeatRecorder $heartbeats, TenantContext $context): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $cutoff = now()->subHours(self::ACTIVATION_AFTER_HOURS);
        $invitationCutoff = now()->subDays(self::INVITATION_AFTER_DAYS);

        // E21.2G: expired one-time credentials and ended invitations are
        // exempt from retention holds by design (the audit ledgers are the
        // held record). `--dry-run` counts with the same predicates.
        if ($this->option('dry-run')) {
            $credentials = AccountActivationCredential::query()->where(fn ($q) => $q->where('expires_at', '<', $cutoff)
                ->orWhere('consumed_at', '<', $cutoff)->orWhere('invalidated_at', '<', $cutoff))->count();
            $invitations = 0;
            foreach (School::query()->orderBy('id')->get() as $school) {
                $invitations += $context->withSchool($school, fn (): int => StaffAccountInvitation::query()->where('school_id', $school->id)
                    ->where(fn ($q) => $q->where('expires_at', '<', $invitationCutoff)->orWhere('accepted_at', '<', $invitationCutoff)->orWhere('revoked_at', '<', $invitationCutoff))->count());
            }
            $this->info("Dry run: would delete {$credentials} ended activation credential(s) and {$invitations} ended staff invitation(s).");

            return self::SUCCESS;
        }

        $ids = AccountActivationCredential::query()
            ->where(fn ($q) => $q->where('expires_at', '<', $cutoff)
                ->orWhere('consumed_at', '<', $cutoff)
                ->orWhere('invalidated_at', '<', $cutoff))
            ->limit($batch)
            ->pluck('id');
        $credentials = $ids->isEmpty() ? 0 : AccountActivationCredential::query()->whereIn('id', $ids)->delete();

        $invitations = 0;

        foreach (School::query()->orderBy('id')->pluck('id') as $schoolId) {
            if ($invitations >= $batch) {
                break;
            }

            $school = School::query()->find($schoolId);
            if ($school === null) {
                continue;
            }

            $invitations += $context->withSchool($school, function () use ($school, $invitationCutoff, $batch, $invitations): int {
                $ids = StaffAccountInvitation::query()
                    ->where('school_id', $school->id)
                    ->where(fn ($q) => $q->where('expires_at', '<', $invitationCutoff)
                        ->orWhere('accepted_at', '<', $invitationCutoff)
                        ->orWhere('revoked_at', '<', $invitationCutoff))
                    ->limit($batch - $invitations)
                    ->pluck('id');

                return $ids->isEmpty() ? 0 : StaffAccountInvitation::query()->whereIn('id', $ids)->delete();
            });
        }

        $heartbeats->recordSuccess('staff-account-credentials-prune');
        Log::info('platform.staff_account_credentials_prune.completed', ['activation_credentials' => $credentials, 'invitations' => $invitations]);
        $this->info("Deleted {$credentials} ended activation credential(s) and {$invitations} ended staff invitation(s).");

        return self::SUCCESS;
    }
}
