<?php

namespace App\Console\Commands;

use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\School;
use App\Support\Email\EmailState;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0O.9A (ADR 0055 section 21): operational retention for email
 * metadata -- [LEGAL REVIEW REQUIRED]. With MAIL_RETENTION_DAYS unset (the
 * default) NOTHING is deleted and the run says so, exactly like
 * WEBHOOKS_DELIVERY_RETENTION_DAYS. When set, it deletes FINISHED messages
 * (their attempts cascade) and processed events older than the period.
 * Suppressions are never pruned (release only), and a message still
 * waiting or observable is never touched. Sealed content is not governed
 * here: it is purged at submission or expiry, long before.
 */
class PruneEmailRecords extends Command
{
    protected $signature = 'platform:email-prune';

    protected $description = 'Apply the configured email metadata retention (ADR 0055; nothing is deleted while unset).';

    public function handle(SchedulerHeartbeatRecorder $heartbeats, TenantContext $context): int
    {
        $days = config('email.retention_days');

        if ($days === null || $days === '' || ! is_numeric($days) || (int) $days < 1) {
            Log::info('platform.email_prune.unconfigured');
            $this->info('Email retention is not configured ([LEGAL REVIEW REQUIRED]); nothing was deleted.');
            $heartbeats->recordSuccess('email-prune');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);
        $messages = 0;

        // Retention maintenance applies to every School, suspended ones too.
        School::query()->orderBy('id')->chunk(100, function ($schools) use ($context, $cutoff, &$messages): void {
            foreach ($schools as $school) {
                $messages += $context->withSchool($school, fn () => EmailMessage::query()
                    ->whereIn('status', array_map(fn (EmailState $s) => $s->value, array_filter(EmailState::cases(), fn (EmailState $s) => $s->isFinal())))
                    ->where('finished_at', '<', $cutoff)
                    ->delete());
            }
        });

        $events = EmailEvent::query()->where('result', '!=', 'received')->where('received_at', '<', $cutoff)->delete();

        $heartbeats->recordSuccess('email-prune');
        Log::info('platform.email_prune.completed', ['messages' => $messages, 'events' => $events]);
        $this->info("Deleted {$messages} finished message(s) and {$events} event(s).");

        return self::SUCCESS;
    }
}
