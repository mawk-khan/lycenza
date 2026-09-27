<?php

namespace App\Console\Commands;

use App\Jobs\ApplyEmailEventJob;
use App\Jobs\SubmitEmailMessageJob;
use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\School;
use App\Support\Email\EmailState;
use App\Support\Email\EmailSubmissionService;
use App\Support\Observability\ErrorReporter;
use App\Support\Observability\QueueName;
use App\Support\Observability\RecoveryMetrics;
use App\Support\Observability\SafeException;
use App\Support\Observability\SchedulerHeartbeatRecorder;
use App\Support\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Phase 0O.9A (ADR 0055 section 10): the email layer's recovery sweep,
 * every minute. Bounded and indexed; it never contacts a provider and
 * never touches readiness.
 *
 * - Active Schools: re-dispatch messages that are due (`pending` with a
 *   passed `next_attempt_at` -- a scheduled retry, a deferral, or an
 *   after-commit dispatch that was lost) and messages whose submission
 *   lease expired (a crashed worker). Re-dispatch is duplicate-safe: the
 *   job's claim decides.
 * - Every School, suspended ones included: cancel messages that outlived
 *   their content lifetime, which purges their sealed content on time.
 *   Nothing is submitted for a non-active School.
 * - Provider events whose application job was lost are queued again.
 */
class RedispatchDueEmailMessages extends Command
{
    protected $signature = 'platform:email-messages-redispatch {--batch=100 : Maximum messages per School per run}';

    protected $description = 'Re-dispatch due or abandoned email messages, expire outlived ones, and re-queue unapplied provider events.';

    public function handle(SchedulerHeartbeatRecorder $heartbeats, EmailSubmissionService $submissions): int
    {
        $startedAt = microtime(true);
        $batch = max(1, (int) $this->option('batch'));
        $dispatched = 0;
        $expired = 0;
        $events = collect();

        try {
            School::query()->orderBy('id')->chunk(100, function ($schools) use ($batch, $submissions, &$dispatched, &$expired): void {
                foreach ($schools as $school) {
                    app(TenantContext::class)->withSchool($school, function () use ($school, $batch, $submissions, &$dispatched, &$expired): void {
                        $expired += $this->expire($submissions, $batch);

                        if ($school->isActive()) {
                            $dispatched += $this->redispatch($school, $batch);
                        }
                    });
                }
            });

            $events = EmailEvent::query()->where('result', 'received')
                ->where('received_at', '<=', now()->subSeconds(60))
                ->orderBy('received_at')->limit($batch)->pluck('id');
            foreach ($events as $id) {
                ApplyEmailEventJob::dispatch($id)->onQueue(QueueName::Notifications->value);
            }
        } catch (\Throwable $e) {
            app(RecoveryMetrics::class)->record('email', false, $startedAt);
            $heartbeats->recordFailure('email-messages-redispatch', SafeException::code($e));
            app(ErrorReporter::class)->report($e, 'platform.email_messages_redispatch.failed', 'scheduler', 'email-messages-redispatch');
            $this->error('Email redispatch failed ('.SafeException::code($e).').');

            return self::FAILURE;
        }

        $heartbeats->recordSuccess('email-messages-redispatch');
        app(RecoveryMetrics::class)->record('email', true, $startedAt, ['inspected' => $dispatched + $expired, 'redispatched' => $dispatched]);
        Log::info('platform.email_messages_redispatch.completed', ['dispatched' => $dispatched, 'expired' => $expired, 'events_requeued' => count($events)]);
        $this->info("Re-dispatched {$dispatched} email message(s); expired {$expired}.");

        return self::SUCCESS;
    }

    private function expire(EmailSubmissionService $submissions, int $batch): int
    {
        $ids = EmailMessage::query()
            ->whereIn('status', [EmailState::Pending->value, EmailState::Submitting->value])
            ->where('expires_at', '<=', now())
            ->limit($batch)
            ->pluck('id');

        foreach ($ids as $id) {
            $submissions->expireIfDue($id);
        }

        return $ids->count();
    }

    private function redispatch(School $school, int $batch): int
    {
        return DB::transaction(function () use ($school, $batch): int {
            $ids = EmailMessage::query()
                ->where(fn ($q) => $q
                    ->where(fn ($p) => $p->where('status', EmailState::Pending->value)->where('next_attempt_at', '<=', now()))
                    ->orWhere(fn ($p) => $p->where('status', EmailState::Submitting->value)->where('processing_lease_expires_at', '<', now())))
                ->orderBy('next_attempt_at')
                ->limit($batch)
                ->lock('for update skip locked')
                ->pluck('id');

            foreach ($ids as $id) {
                SubmitEmailMessageJob::dispatch($school->id, $id)->onQueue(QueueName::Notifications->value)->afterCommit();
            }

            return $ids->count();
        });
    }
}
