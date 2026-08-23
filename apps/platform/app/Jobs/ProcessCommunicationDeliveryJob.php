<?php

namespace App\Jobs;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
use App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationDeliveryAttempt;
use App\Models\School;
use App\Support\Tenancy\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

/**
 * Phase 5A.1 §2.9 -- ONE delivery attempt per invocation, mirroring
 * App\Jobs\DeliverWebhookJob's shape exactly: atomic processing-lease
 * claim BEFORE any driver call (so two workers racing the same delivery
 * id cannot both act), one append-only CommunicationDeliveryAttempt row
 * per real attempt, $tries = 1 because this job owns its own retry
 * state via the delivery's own status/next_attempt_at columns (root
 * CLAUDE.md rule 59).
 *
 * School context comes from an explicit constructor argument (like
 * DeliverWebhookJob), not TenantScoped's ambient capture, since the
 * dispatching request/service already knows exactly which School this
 * delivery belongs to.
 *
 * Phase 5A.3 §19/§21 extended this job, without redesigning it: a
 * driver's success now carries its own terminal `status` (in_app's
 * `delivered` vs a real external channel's `sent` --
 * CommunicationDeliveryResult::delivered()/sent()), and a `retryable`
 * failure is scheduled for a bounded, backed-off re-attempt
 * (App\Console\Commands\RedispatchDueCommunicationDeliveries drives
 * the actual re-dispatch, mirroring RedispatchDueWebhookDeliveries)
 * rather than immediately terminal -- `in_app` never exercises either
 * new path (it always succeeds with `delivered`), so this checkpoint's
 * only NEW runtime behavior belongs to `email`.
 */
class ProcessCommunicationDeliveryJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 15;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $deliveryId,
    ) {}

    public function handle(CommunicationChannelRegistry $registry, TenantContext $context, CommunicationDeliveryTimingPolicyService $timingPolicy): void
    {
        $school = School::query()->find($this->schoolId);

        if ($school === null) {
            return;
        }

        try {
            $context->set($school);
            $this->process($registry, $timingPolicy, $school);
        } finally {
            $context->clearAll();
        }
    }

    private function process(CommunicationChannelRegistry $registry, CommunicationDeliveryTimingPolicyService $timingPolicy, School $school): void
    {
        $delivery = $this->claim();

        if ($delivery === null) {
            return;
        }

        $channel = $delivery->channelEnum();
        $driver = $registry->driver($channel);
        $attemptNumber = $delivery->attempts + 1;
        $startedAt = now();

        if ($driver === null) {
            $this->recordAttempt($delivery, $attemptNumber, $startedAt, 'permanent_failure', null, 'channel_not_supported', "No driver registered for '{$channel->value}'.");
            $delivery->update([
                'status' => 'failed',
                'attempts' => $attemptNumber,
                'failed_at' => now(),
                'failure_code' => 'channel_not_supported',
                'processing_lease_expires_at' => null,
            ]);

            return;
        }

        $result = $driver->send($delivery);

        if ($result->success) {
            $status = $result->status ?? 'delivered';
            $this->recordAttempt($delivery, $attemptNumber, $startedAt, 'success', $result->providerReference, null, null);
            $delivery->update([
                'status' => $status,
                'attempts' => $attemptNumber,
                'sent_at' => $delivery->sent_at ?? now(),
                'delivered_at' => $status === 'delivered' ? now() : null,
                'processing_lease_expires_at' => null,
            ]);

            return;
        }

        if ($result->retryable && $attemptNumber < (int) config('communications.delivery.max_attempts')) {
            $this->recordAttempt($delivery, $attemptNumber, $startedAt, 'transient_failure', null, $result->failureCode, $result->failureMessage);
            $this->scheduleRetry($delivery, $attemptNumber, $timingPolicy, $school);

            return;
        }

        $this->recordAttempt($delivery, $attemptNumber, $startedAt, 'permanent_failure', null, $result->failureCode, $result->failureMessage);
        $delivery->update([
            'status' => 'failed',
            'attempts' => $attemptNumber,
            'failed_at' => now(),
            'failure_code' => $result->failureCode,
            'failure_reason' => $result->failureMessage,
            'processing_lease_expires_at' => null,
        ]);
    }

    /**
     * Mirrors DeliverWebhookJob::scheduleRetryOrAbandon()'s backoff
     * shape (brief §21): `queued` (an existing, already-valid status
     * in the closed set -- no new status added) with `next_attempt_at`
     * set is what
     * App\Console\Commands\RedispatchDueCommunicationDeliveries scans
     * for. A max-attempts-exhausted retryable failure falls through to
     * the same terminal `failed` handling as a non-retryable one, one
     * call site up.
     *
     * Phase 5A.9 §33/§57: the computed backoff timestamp is itself
     * re-checked against quiet-hours timing eligibility -- a retry
     * that would otherwise land inside a quiet window is pushed out to
     * the next permitted instant instead, so "every provider attempt
     * respects current timing eligibility," not just the first one.
     * This does NOT re-evaluate the ORIGINAL deferral decision made at
     * delivery-creation time (brief §29's immutable-planning
     * invariant) -- it only ever widens a freshly-computed retry
     * candidate, never touches a delivery that hasn't just failed.
     */
    private function scheduleRetry(CommunicationDelivery $delivery, int $attemptNumber, CommunicationDeliveryTimingPolicyService $timingPolicy, School $school): void
    {
        $schedule = config('communications.delivery.retry_backoff_seconds');
        $delaySeconds = $schedule[min($attemptNumber - 1, count($schedule) - 1)];
        $candidate = now()->addSeconds($delaySeconds);

        $timingDecision = $timingPolicy->evaluate($school, $delivery->channelEnum(), $candidate);

        $delivery->update([
            'status' => 'queued',
            'attempts' => $attemptNumber,
            'processing_lease_expires_at' => null,
            'next_attempt_at' => $timingDecision->shouldDefer ? $timingDecision->availableAt : $candidate,
        ]);
    }

    /**
     * Same shape as DeliverWebhookJob::claim() -- a conditional UPDATE,
     * not a check-then-act. A delivery already claimed by another
     * worker (unexpired lease) or already terminal yields 0 affected
     * rows and this method returns null.
     */
    private function claim(): ?CommunicationDelivery
    {
        $claimed = CommunicationDelivery::query()
            ->where('id', $this->deliveryId)
            ->whereIn('status', ['pending', 'queued'])
            ->where(function ($query) {
                $query->whereNull('processing_lease_expires_at')
                    ->orWhere('processing_lease_expires_at', '<', now());
            })
            ->update([
                'status' => 'sending',
                'processing_lease_expires_at' => now()->addSeconds((int) config('communications.delivery.processing_lease_seconds')),
            ]);

        if ($claimed === 0) {
            return null;
        }

        return CommunicationDelivery::query()->find($this->deliveryId);
    }

    private function recordAttempt(
        CommunicationDelivery $delivery,
        int $attemptNumber,
        Carbon $startedAt,
        string $outcome,
        ?string $providerReference,
        ?string $failureCode,
        ?string $failureMessage,
    ): void {
        CommunicationDeliveryAttempt::query()->create([
            'school_id' => $delivery->school_id,
            'communication_delivery_id' => $delivery->id,
            'attempt_number' => $attemptNumber,
            'started_at' => $startedAt,
            'completed_at' => now(),
            'outcome' => $outcome,
            'provider_reference' => $providerReference,
            'failure_code' => $failureCode,
            'failure_message' => $failureMessage,
            'duration_ms' => (int) round($startedAt->diffInMilliseconds(now())),
        ]);
    }
}
