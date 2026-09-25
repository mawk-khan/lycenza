<?php

namespace App\Jobs;

use App\Models\DomainEventOutbox;
use App\Models\School;
use App\Models\WebhookDelivery;
use App\Models\WebhookDeliveryAttempt;
use App\Support\Tenancy\SchoolOperationalGuard;
use App\Support\Tenancy\TenantContext;
use App\Support\Webhooks\SsrfRejectedException;
use App\Support\Webhooks\SsrfSafeUrlValidator;
use App\Support\Webhooks\WebhookSigner;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Real, queued, non-blocking webhook delivery -- ONE HTTP attempt per
 * invocation (section 17: a logical delivery may have many attempts,
 * this job records exactly one). School context comes from an explicit
 * constructor argument (captured by WebhookFanoutConsumer, which
 * already has it from ProcessOutboxEventJob) -- not TenantScoped's
 * "capture from ambient context," since this job isn't dispatched from
 * an HTTP request.
 *
 * Retries are NOT driven by Laravel's job-level $tries/$backoff (the
 * sync queue connection used in tests, ADR 0024, does not implement
 * delayed requeueing) -- this job always completes after one attempt,
 * updating the delivery's own `status`/`next_attempt_at`.
 * App\Console\Commands\RedispatchDueWebhookDeliveries is what
 * re-dispatches this job for deliveries whose retry is due, and is
 * also what recovers a delivery whose worker crashed mid-attempt (see
 * the lease claim below).
 *
 * Concurrency safety (sections 44/45): the delivery is claimed via one
 * atomic conditional UPDATE (an explicit "processing lease," the same
 * pattern as App\Support\Idempotency\IdempotencyGuard's reclaim logic)
 * BEFORE any HTTP call is made. A second worker racing the same
 * delivery_id sees 0 affected rows and returns immediately WITHOUT
 * attempting delivery -- there is no window in which two workers can
 * both send the HTTP request for the same delivery.
 *
 * SSRF (section 27/31): re-validated at SEND time (not just at
 * endpoint creation, since DNS can change) via SsrfSafeUrlValidator,
 * and the validated IP is pinned via CURLOPT_RESOLVE so the actual TCP
 * connection cannot be redirected by a DNS change between validation
 * and connection -- this narrows, though does not perfectly eliminate,
 * the DNS-rebinding window (see SsrfSafeUrlValidator's docblock).
 * Redirects are never followed automatically (section 33).
 */
class DeliverWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 30;

    public function __construct(
        public readonly string $schoolId,
        public readonly string $deliveryId,
    ) {}

    public function handle(SsrfSafeUrlValidator $ssrf, WebhookSigner $signer, TenantContext $context): void
    {
        $school = School::query()->find($this->schoolId);

        if ($school === null) {
            return;
        }

        try {
            $context->set($school);
            $this->deliver($ssrf, $signer);
        } finally {
            $context->clearAll();
        }
    }

    private function deliver(SsrfSafeUrlValidator $ssrf, WebhookSigner $signer): void
    {
        $delivery = $this->claim();

        if ($delivery === null) {
            return;
        }

        $endpoint = $delivery->endpoint;

        if ($endpoint === null || ! $endpoint->isActive()) {
            $this->abandon($delivery, 'endpoint_unavailable');

            return;
        }

        $event = DomainEventOutbox::query()->find($delivery->event_id);

        if ($event === null) {
            $this->abandon($delivery, 'event_missing');

            return;
        }

        $attemptNumber = $delivery->attempts + 1;
        $body = json_encode([
            'id' => $event->id,
            'type' => $event->event_type,
            'version' => $event->event_version,
            'createdAt' => $event->occurred_at->toIso8601String(),
            'schoolId' => $event->school_id,
            'data' => $event->payload,
            'metadata' => ['correlationId' => $event->correlation_id],
        ], JSON_THROW_ON_ERROR);

        $timestamp = time();
        // Raw hex HMAC only -- section 23's header contract carries the
        // timestamp and signature version in their OWN dedicated
        // headers (X-SchoolOS-Timestamp / X-SchoolOS-Signature-Version),
        // so this header is not the combined "t=...,v1=..." string
        // WebhookSigner::header() produces for other (e.g. Stripe-style
        // single-header) integrations -- see WebhookSigner's docblock.
        $signature = $signer->sign((string) $endpoint->secret_encrypted, $delivery->id, $timestamp, $body);

        $startedAt = now();
        $start = microtime(true);

        try {
            $ip = $ssrf->assertSafeAndResolve($endpoint->url);
        } catch (SsrfRejectedException $e) {
            $this->recordAttempt($delivery, $attemptNumber, $startedAt, $start, null, 'permanent_failure', 'ssrf_rejected');
            $this->abandon($delivery, 'ssrf_rejected', $attemptNumber);
            Log::warning('webhook.delivery.ssrf_rejected', [
                'school_id' => $this->schoolId,
                'delivery_id' => $delivery->id,
                'correlation_id' => $event->correlation_id,
            ]);

            return;
        }

        $parsed = parse_url($endpoint->url);
        $port = $parsed['port'] ?? (($parsed['scheme'] ?? 'https') === 'https' ? 443 : 80);

        try {
            $response = Http::withHeaders([
                'X-SchoolOS-Event-Id' => $event->id,
                'X-SchoolOS-Delivery-Id' => $delivery->id,
                'X-SchoolOS-Event-Type' => $event->event_type,
                'X-SchoolOS-Timestamp' => (string) $timestamp,
                'X-SchoolOS-Signature-Version' => 'v1',
                'X-SchoolOS-Signature' => $signature,
                'Content-Type' => 'application/json',
            ])
                ->withOptions([
                    'allow_redirects' => false,
                    'curl' => [
                        CURLOPT_RESOLVE => ["{$parsed['host']}:{$port}:{$ip}"],
                    ],
                ])
                ->connectTimeout((int) config('webhooks.connect_timeout_seconds'))
                ->timeout((int) config('webhooks.delivery_timeout_seconds'))
                ->send('POST', $endpoint->url, ['body' => $body]);

            $this->handleResponse($delivery, $attemptNumber, $startedAt, $start, $response);
        } catch (ConnectionException $e) {
            $isTimeout = str_contains(strtolower($e->getMessage()), 'timed out') || str_contains(strtolower($e->getMessage()), 'timeout');
            $this->recordAttempt($delivery, $attemptNumber, $startedAt, $start, null, $isTimeout ? 'timeout' : 'network_error', $isTimeout ? 'timeout' : 'network_error');
            $this->scheduleRetryOrAbandon($delivery, $attemptNumber, null);
        }

        Log::info('webhook.delivery.attempted', [
            'school_id' => $this->schoolId,
            'delivery_id' => $delivery->id,
            'endpoint_id' => $endpoint->id,
            'attempt_number' => $attemptNumber,
            'correlation_id' => $event->correlation_id,
        ]);
    }

    /**
     * Atomically claims this delivery for THIS worker (sections 44/45):
     * `pending`/`retrying` rows have no active lease by definition;
     * `delivering` rows are only reclaimable once their lease has
     * expired (a crashed worker's stale claim). Any row NOT matching
     * (already delivered/failed/abandoned, or another worker's live
     * lease) yields 0 affected rows -- this worker does nothing further.
     */
    private function claim(): ?WebhookDelivery
    {
        $leaseSeconds = (int) config('webhooks.processing_lease_seconds');

        // Phase 0N.9 (ADR 0047 section 8): the School must be active when
        // the delivery is claimed -- read FOR SHARE in the same
        // transaction as the claim, so a suspension either commits first
        // (and this delivery is deferred) or waits until this claim has
        // committed (in-flight work). The HTTP call itself runs after
        // commit, never under the lock.
        return DB::transaction(function () use ($leaseSeconds): ?WebhookDelivery {
            $claimable = WebhookDelivery::query()
                ->where('id', $this->deliveryId)
                ->whereIn('status', ['pending', 'retrying', 'delivering'])
                ->where(function ($query) {
                    $query->whereNull('processing_lease_expires_at')
                        ->orWhere('processing_lease_expires_at', '<', now());
                });

            if (! app(SchoolOperationalGuard::class)->holdOperational($this->schoolId)) {
                // Deferred, not attempted: kept as `retrying`, due again at
                // once -- the redispatcher skips non-active Schools, so it
                // is picked up only after RESUME. No attempt is consumed
                // and no attempt row is written.
                $claimable->update(['status' => 'retrying', 'next_attempt_at' => now(), 'processing_lease_expires_at' => null]);

                return null;
            }

            $claimed = $claimable->update([
                'status' => 'delivering',
                'processing_lease_expires_at' => now()->addSeconds($leaseSeconds),
            ]);

            return $claimed === 0 ? null : WebhookDelivery::query()->find($this->deliveryId);
        });
    }

    private function handleResponse(WebhookDelivery $delivery, int $attemptNumber, Carbon $startedAt, float $start, $response): void
    {
        $status = $response->status();

        // Section 36-40's classification.
        $outcome = match (true) {
            $status >= 200 && $status < 300 => 'success',
            $status === 408 || $status === 429 => 'transient_failure',
            $status >= 300 && $status < 500 => 'permanent_failure',
            default => 'transient_failure', // 5xx
        };

        $this->recordAttempt($delivery, $attemptNumber, $startedAt, $start, $status, $outcome, (string) $status);

        if ($outcome === 'success') {
            $delivery->update([
                'status' => 'delivered',
                'attempts' => $attemptNumber,
                'delivered_at' => now(),
                'processing_lease_expires_at' => null,
            ]);

            return;
        }

        if ($outcome === 'permanent_failure') {
            $delivery->update(['status' => 'failed', 'attempts' => $attemptNumber, 'processing_lease_expires_at' => null]);

            return;
        }

        $this->scheduleRetryOrAbandon($delivery, $attemptNumber, $this->parseRetryAfter($response));
    }

    /**
     * Section 42: a valid, bounded Retry-After (seconds or HTTP-date)
     * overrides the fixed backoff schedule for this attempt; an
     * absurd/hostile value is clamped to webhooks.max_retry_after_seconds.
     */
    private function parseRetryAfter($response): ?int
    {
        $header = $response?->header('Retry-After');

        if ($header === null || $header === '') {
            return null;
        }

        $max = (int) config('webhooks.max_retry_after_seconds');

        if (is_numeric($header)) {
            return max(0, min((int) $header, $max));
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return max(0, min($timestamp - time(), $max));
    }

    private function scheduleRetryOrAbandon(WebhookDelivery $delivery, int $attemptNumber, ?int $retryAfterSeconds): void
    {
        $maxAttempts = (int) config('webhooks.max_attempts');

        if ($attemptNumber >= $maxAttempts) {
            $delivery->update([
                'status' => 'abandoned',
                'attempts' => $attemptNumber,
                'processing_lease_expires_at' => null,
                'next_attempt_at' => null,
            ]);

            return;
        }

        $schedule = config('webhooks.retry_backoff_seconds');
        $scheduledDelay = $schedule[min($attemptNumber - 1, count($schedule) - 1)];
        $delaySeconds = $retryAfterSeconds !== null ? max($retryAfterSeconds, $scheduledDelay) : $scheduledDelay;

        // Jitter (section 41): +/-10%, so many deliveries scheduled for
        // the same instant don't all retry in lockstep.
        $jitter = (int) round($delaySeconds * 0.1 * (random_int(0, 1) === 0 ? -1 : 1));

        $delivery->update([
            'status' => 'retrying',
            'attempts' => $attemptNumber,
            'processing_lease_expires_at' => null,
            'next_attempt_at' => now()->addSeconds(max(1, $delaySeconds + $jitter)),
        ]);
    }

    private function abandon(WebhookDelivery $delivery, string $reason, ?int $attemptNumber = null): void
    {
        $attributes = ['status' => 'abandoned', 'processing_lease_expires_at' => null];

        if ($attemptNumber !== null) {
            $attributes['attempts'] = $attemptNumber;
        }

        $delivery->update($attributes);

        Log::warning('webhook.delivery.abandoned', [
            'school_id' => $this->schoolId,
            'delivery_id' => $delivery->id,
            'reason' => $reason,
        ]);
    }

    /**
     * Section 17: exactly one durable, append-only attempt record per
     * real HTTP attempt. Never persists Authorization headers, the
     * webhook secret, or the response body (section 72) -- only status,
     * timing, and a short diagnostic label.
     */
    private function recordAttempt(
        WebhookDelivery $delivery,
        int $attemptNumber,
        Carbon $startedAt,
        float $start,
        ?int $responseStatus,
        string $outcome,
        ?string $errorClass,
    ): void {
        WebhookDeliveryAttempt::query()->create([
            'school_id' => $delivery->school_id,
            'webhook_delivery_id' => $delivery->id,
            'attempt_number' => $attemptNumber,
            'started_at' => $startedAt,
            'completed_at' => now(),
            'response_status' => $responseStatus,
            'duration_ms' => (int) round((microtime(true) - $start) * 1000),
            'outcome' => $outcome,
            'error_class' => $errorClass,
        ]);
    }
}
