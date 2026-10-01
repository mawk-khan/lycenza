<?php

namespace App\Console\Commands;

use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\EmailProviderReference;
use App\Models\School;
use App\Support\Email\EmailState;
use App\Support\Email\PlatformEmailScope;
use App\Support\Observability\SchedulerHeartbeatRecorder;
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
 * ADR 0055 section 21 + E21-D2 (docs/security/E21-RETENTION-DETERMINATION.md,
 * project-adopted, pending legal ratification): email metadata retention.
 * MAIL_RETENTION_DAYS (adopted: 180) has no default. While it is unset
 * NOTHING is deleted and the run says so.
 *
 * Once set, it deletes, older than the period:
 * - FINISHED messages, by their terminal-state timestamp (`finished_at`).
 *   Their attempts cascade, and their provider references go in the same
 *   transaction (E21.1 L2). This covers every School's messages, held
 *   Schools excepted, each inside its own TenantContext. It also covers
 *   identity-level messages (no School), inside PlatformEmailScope
 *   (E21.1 L1).
 * - Processed provider events, except one that a suppression still
 *   references through `source_event_id`. The event is kept while its
 *   suppression exists, so the FK's SET NULL never meets the suppression
 *   guard trigger (E21.1 L3).
 *
 * A message still waiting or observable is never touched. Suppressions are
 * never deleted here (release only; their post-release expiry is E21.2B).
 * Sealed content is purged at submission or expiry, long before.
 *
 * Every DELETE re-applies its predicate (retry-safe), runs in bounded
 * batches, and logs counts only. `--dry-run` only counts.
 */
class PruneEmailRecords extends Command
{
    protected $signature = 'platform:email-prune
        {--dry-run : Count what would be pruned without deleting anything}';

    protected $description = 'Apply the configured email metadata retention (ADR 0055; E21-D2; nothing is deleted while unset).';

    public function handle(SchedulerHeartbeatRecorder $heartbeats, TenantContext $context, PlatformEmailScope $platformScope, RetentionHolds $holds): int
    {
        try {
            $days = RetentionPeriod::days(config('email.retention_days'));
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($days === null) {
            Log::info('platform.email_prune.unconfigured');
            $this->info('Email retention is not configured (MAIL_RETENTION_DAYS); nothing was deleted.');
            $heartbeats->recordSuccess('email-prune');

            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $cutoff = Carbon::now()->subDays($days);
        $batch = max(1, (int) config('retention.batch_size'));
        $messages = 0;
        $heldSchools = 0;

        // Retention applies to every School, suspended ones too (the
        // lifecycle guard's allowlist); a held School is skipped whole.
        School::query()->orderBy('id')->chunk(100, function ($schools) use ($context, $holds, $cutoff, $batch, $dryRun, &$messages, &$heldSchools): void {
            foreach ($schools as $school) {
                if ($holds->isHeld($school->id)) {
                    $heldSchools++;

                    continue;
                }

                $messages += $context->withSchool($school, fn () => $this->pruneMessages($cutoff, $batch, $dryRun));
            }
        });

        // E21.1 L1: identity-level mail (account recovery, security notices).
        // Provider events belong to no School either. Both are held by
        // RETENTION_HOLD_PLATFORM (E21.2B).
        $platformHeld = $holds->platformHeld();
        $identityMessages = $platformHeld ? 0 : $platformScope->run(fn () => $this->pruneMessages($cutoff, $batch, $dryRun));
        $events = $platformHeld ? 0 : $this->pruneEvents($cutoff, $batch, $dryRun);

        $heartbeats->recordSuccess('email-prune');
        Log::info($dryRun ? 'platform.email_prune.dry_run' : 'platform.email_prune.completed', [
            'retention_days' => $days,
            'messages' => $messages,
            'identity_messages' => $identityMessages,
            'events' => $events,
            'held_schools' => $heldSchools,
        ]);
        $this->info(($dryRun ? 'Dry run: would delete' : 'Deleted')." {$messages} School message(s), {$identityMessages} identity-level message(s) and {$events} event(s); {$heldSchools} School(s) held.");

        return self::SUCCESS;
    }

    /**
     * The active scope (one School, or identity-level) decides which
     * messages are visible. Each batch deletes the messages and their
     * provider references together.
     */
    private function pruneMessages(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        if ($dryRun) {
            return $this->finishedBefore($cutoff)->count();
        }

        $deleted = 0;

        do {
            $ids = $this->finishedBefore($cutoff)->orderBy('id')->limit($batch)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += DB::transaction(function () use ($ids, $cutoff): int {
                EmailProviderReference::query()->whereIn('email_message_id', $ids)->delete();

                return $this->finishedBefore($cutoff)->whereIn('id', $ids)->delete();
            });
        } while (count($ids) === $batch);

        return $deleted;
    }

    /** @return Builder<EmailMessage> */
    private function finishedBefore(Carbon $cutoff): Builder
    {
        return EmailMessage::query()
            ->whereIn('status', array_map(fn (EmailState $s) => $s->value, array_filter(EmailState::cases(), fn (EmailState $s) => $s->isFinal())))
            ->where('finished_at', '<', $cutoff);
    }

    private function pruneEvents(Carbon $cutoff, int $batch, bool $dryRun): int
    {
        $eligible = fn () => EmailEvent::query()
            ->where('result', '!=', 'received')
            ->where('received_at', '<', $cutoff)
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('email_suppressions')->whereColumn('email_suppressions.source_event_id', 'email_events.id'));

        if ($dryRun) {
            return $eligible()->count();
        }

        $deleted = 0;

        do {
            $ids = $eligible()->orderBy('id')->limit($batch)->pluck('id')->all();

            if ($ids === []) {
                break;
            }

            $deleted += $eligible()->whereIn('id', $ids)->delete();
        } while (count($ids) === $batch);

        return $deleted;
    }
}
