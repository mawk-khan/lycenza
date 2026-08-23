<?php

namespace App\Jobs;

use App\Domain\Communications\Application\Channels\CommunicationChannelRegistry;
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

    public function handle(CommunicationChannelRegistry $registry, TenantContext $context): void
    {
        $school = School::query()->find($this->schoolId);

        if ($school === null) {
            return;
        }

        try {
            $context->set($school);
            $this->process($registry);
        } finally {
            $context->clearAll();
        }
    }

    private function process(CommunicationChannelRegistry $registry): void
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
            $this->recordAttempt($delivery, $attemptNumber, $startedAt, 'success', $result->providerReference, null, null);
            $delivery->update([
                'status' => 'delivered',
                'attempts' => $attemptNumber,
                'sent_at' => $delivery->sent_at ?? now(),
                'delivered_at' => now(),
                'processing_lease_expires_at' => null,
            ]);

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
                'processing_lease_expires_at' => now()->addSeconds(30),
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
