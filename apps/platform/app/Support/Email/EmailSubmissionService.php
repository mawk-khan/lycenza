<?php

namespace App\Support\Email;

use App\Models\EmailMessage;
use App\Models\EmailProviderReference;
use App\Models\EmailSubmissionAttempt;
use App\Support\Email\Providers\EmailProviderAdapter;
use App\Support\Email\Providers\EmailProviderResolver;
use App\Support\Email\Providers\OutboundEmail;
use App\Support\Email\Providers\SimulatedWorkerCrash;
use App\Support\Email\Providers\SubmissionOutcome;
use App\Support\Email\Providers\SubmissionResult;
use App\Support\Email\Suppression\EmailSuppressionService;
use App\Support\Observability\SafeException;
use App\Support\Tenancy\SchoolOperationalGuard;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * ADR 0055 sections 9.3 and 10: ONE submission attempt of one message, in
 * three steps -- claim (a short transaction), the provider call (outside
 * every transaction, rule 38), then recording the outcome (a short
 * transaction). Runs inside the message's School TenantContext
 * (App\Jobs\SubmitEmailMessageJob).
 *
 * Claim: under a global advisory lock (so the fairness counts are exact)
 * and a row lock, a `pending` message that is due -- or a `submitting`
 * message whose lease expired (a crashed worker) -- is re-checked for
 * expiry, whether its source still wants it, the School lifecycle
 * (SchoolOperationalGuard, FOR SHARE), the provider mode, a provider
 * authentication pause, suppression and the throughput budgets, and only
 * then leased (`submitting`). Every "not now" DEFERS the message; only
 * expiry, a withdrawn source and suppression end it.
 *
 * At-least-once, never exactly-once: every attempt reuses the message's
 * RFC Message-ID and idempotency key; if the provider accepts and this
 * worker dies before recording it, the lease expires and the message is
 * submitted AGAIN. A provider that honours the idempotency key collapses
 * that into one message; one that does not may deliver a duplicate. That
 * residual is documented (ADR 0055 section 9.3), never hidden.
 */
final class EmailSubmissionService
{
    /** pg_advisory_xact_lock key serializing claims (fairness counts). */
    public const CLAIM_LOCK = 7_455_550_055;

    public function __construct(
        private readonly Repository $config,
        private readonly EmailProviderResolver $providers,
        private readonly SenderIdentity $sender,
        private readonly EmailSources $sources,
        private readonly EmailSuppressionService $suppressions,
        private readonly EmailThroughput $throughput,
        private readonly ProviderAuthPause $authPause,
        private readonly RetrySchedule $retries,
        private readonly SchoolOperationalGuard $schools,
        private readonly EmailTelemetry $telemetry,
    ) {}

    public function process(string $messageId): void
    {
        $message = $this->claim($messageId);

        if ($message === null) {
            return;
        }

        $adapter = $this->providers->adapter();
        if ($adapter === null) {
            // The mode changed between claim and submit: wait again.
            $this->record($message, SubmissionResult::transient('provider_unavailable'), 'none', now(), 0, counted: false);

            return;
        }

        $email = $this->outbound($message);
        $startedAt = now();
        $start = hrtime(true);

        try {
            $result = $adapter->submit($email);
        } catch (SimulatedWorkerCrash $e) {
            throw $e;
        } catch (Throwable $e) {
            // An adapter must classify, never throw; a throw is treated as a
            // transient provider error and reported by code only.
            Log::warning('email.submission.adapter_error', ['email_message_id' => $message->id, 'error_code' => SafeException::code($e)]);
            $result = SubmissionResult::transient('provider_error');
        }

        $this->record($message, $result, $adapter->name(), $startedAt, (int) ((hrtime(true) - $start) / 1_000_000));
    }

    public function claim(string $messageId): ?EmailMessage
    {
        $outcome = DB::transaction(function () use ($messageId): ?array {
            DB::select('SELECT pg_advisory_xact_lock(?)', [self::CLAIM_LOCK]);

            $message = EmailMessage::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($message === null || ! $message->status->awaitsSubmission()) {
                return null;
            }

            $now = now();

            if ($message->status === EmailState::Submitting) {
                if ($message->processing_lease_expires_at !== null && $message->processing_lease_expires_at->isAfter($now)) {
                    return null; // another worker owns it
                }
                // A crashed worker's lease: back to pending, then re-checked.
                $message->forceFill(['status' => EmailState::Pending, 'processing_lease_expires_at' => null])->save();
            } elseif ($message->next_attempt_at !== null && $message->next_attempt_at->isAfter($now)) {
                return null; // not due
            }

            if ($message->expires_at->lessThanOrEqualTo($now)) {
                return $this->finish($message, EmailState::Cancelled, 'expired');
            }

            $source = $this->sources->for($message->source_type);
            if ($source === null || ! $source->isStillWanted($message)) {
                return $this->finish($message, EmailState::Cancelled, 'source_withdrawn');
            }

            // ADR 0056 section 9.3: an identity-level message has no School;
            // a School's lifecycle is never authority over an identity.
            if ($message->school_id !== null && ! $this->schools->holdOperational($message->school_id)) {
                // Waits until the School is RESUMED; the sweeper skips
                // non-active Schools, so this does not loop.
                return $this->defer($message, $now, 'school_not_operational');
            }

            if (($blocked = $this->providers->blockedReason()) !== null) {
                return $this->defer($message, $now->copy()->addSeconds((int) $this->config->get('email.submission.disabled_recheck_seconds')), $blocked);
            }

            if (($pausedUntil = $this->authPause->until()) !== null) {
                return $this->defer($message, $pausedUntil, 'provider_auth_paused');
            }

            if (($suppression = $this->suppressions->blocking($message->recipient(), $message->kind)) !== null) {
                return $this->finish($message, EmailState::Suppressed, 'suppressed_'.$suppression->reason);
            }

            if (! $this->throughput->inFlightAllows($message)) {
                return $this->defer($message, $now->copy()->addSeconds(15), 'in_flight_limit');
            }

            if (($wait = $this->throughput->takeRate($message)) > 0) {
                return $this->defer($message, $now->copy()->addSeconds($wait), 'rate_budget');
            }

            $domain = $this->sender->sendingDomain();
            $message->forceFill([
                'status' => EmailState::Submitting,
                'processing_lease_expires_at' => $now->copy()->addSeconds((int) $this->config->get('email.submission.lease_seconds')),
                'rfc_message_id' => $message->rfc_message_id ?? ($domain !== null ? EmailMessageIdentity::rfcMessageId($message->id, $domain) : null),
            ])->save();

            return ['claimed' => $message];
        });

        if ($outcome === null) {
            return null;
        }

        if (isset($outcome['finished'])) {
            $this->afterChange($outcome['finished']);

            return null;
        }

        return $outcome['claimed'];
    }

    /**
     * Records one attempt and applies its outcome. `counted: false` is used
     * only when no provider was contacted at all (no attempt row).
     */
    public function record(EmailMessage $claimed, SubmissionResult $result, string $provider, CarbonInterface $startedAt, int $durationMs, bool $counted = true): void
    {
        $changed = DB::transaction(function () use ($claimed, $result, $provider, $startedAt, $durationMs, $counted): ?EmailMessage {
            $message = EmailMessage::query()->whereKey($claimed->id)->lockForUpdate()->first();

            if ($message === null) {
                return null;
            }

            $attempt = $message->attempts + ($counted ? 1 : 0);

            if ($counted) {
                EmailSubmissionAttempt::query()->create([
                    'school_id' => $message->school_id,
                    'email_message_id' => $message->id,
                    'attempt_number' => $attempt,
                    'started_at' => $startedAt,
                    'completed_at' => now(),
                    'outcome' => $result->outcome->value,
                    'failure_code' => $result->code,
                    'provider' => $provider,
                    'provider_message_id' => $result->providerMessageId,
                    'duration_ms' => max(0, $durationMs),
                ]);
                $this->telemetry->attempt($message->purpose, $result->outcome->value);
            }

            // Another worker reclaimed this message after our lease expired:
            // the attempt is evidence, but the state is no longer ours.
            if ($message->status !== EmailState::Submitting) {
                if ($counted) {
                    $message->forceFill(['attempts' => max($message->attempts, $attempt)])->save();
                }

                return null;
            }

            $base = ['attempts' => $attempt, 'processing_lease_expires_at' => null, 'provider' => $provider === 'none' ? $message->provider : $provider];
            $spent = $attempt - $message->retry_base;

            switch ($result->outcome) {
                case SubmissionOutcome::Accepted:
                    $message->forceFill([...$base,
                        'status' => EmailState::Submitted,
                        'status_code' => null,
                        'submitted_at' => now(),
                        'next_attempt_at' => null,
                        'provider_message_id' => $result->providerMessageId,
                    ])->save();

                    if ($result->providerMessageId !== null) {
                        EmailProviderReference::query()->insertOrIgnore([
                            'provider' => $provider,
                            'provider_message_id' => $result->providerMessageId,
                            'email_message_id' => $message->id,
                            'school_id' => $message->school_id,
                            'created_at' => now(),
                        ]);
                    }

                    return $message;

                case SubmissionOutcome::PermanentFailure:
                    return $this->finish($message, EmailState::Failed, (string) $result->code, $base)['finished'];

                case SubmissionOutcome::AuthFailure:
                    $until = $this->authPause->open((int) $this->config->get('email.submission.auth_failure_pause_seconds'));
                    Log::error('email.provider.auth_failure', ['provider' => $provider, 'code' => $result->code]);

                    return $spent >= $this->retries->maxAttempts() || $until->greaterThanOrEqualTo($message->expires_at)
                        ? $this->finish($message, EmailState::Failed, 'attempts_exhausted', $base)['finished']
                        : $this->deferModel($message, $until, 'provider_auth_failure', $base);

                default:
                    $next = now()->addSeconds($counted ? $this->retries->delayAfter($spent) : (int) $this->config->get('email.submission.disabled_recheck_seconds'));

                    if ($spent >= $this->retries->maxAttempts()) {
                        return $this->finish($message, EmailState::Failed, 'attempts_exhausted', $base)['finished'];
                    }
                    if ($next->greaterThanOrEqualTo($message->expires_at)) {
                        return $this->finish($message, EmailState::Failed, 'expired', $base)['finished'];
                    }

                    return $this->deferModel($message, $next, (string) $result->code, $base);
            }
        });

        if ($changed !== null) {
            $this->afterChange($changed);
        }
    }

    /**
     * Operator retry (platform:mail-retry): a WAITING message is made due now
     * and, once in its life, gets a fresh attempt budget. Every claim check
     * still runs. A finished message cannot be retried -- its content is
     * purged; the product action (e.g. resending the invitation) re-issues it.
     */
    public function retryNow(EmailMessage $message): bool
    {
        return DB::transaction(function () use ($message): bool {
            $fresh = EmailMessage::query()->whereKey($message->id)->lockForUpdate()->first();

            if ($fresh === null || $fresh->status !== EmailState::Pending) {
                return false;
            }

            $fresh->forceFill([
                'next_attempt_at' => now(),
                'retry_base' => $fresh->manual_retries === 0 ? $fresh->attempts : $fresh->retry_base,
                'manual_retries' => 1,
            ])->save();

            return true;
        });
    }

    /**
     * Content lifetime (ADR 0055 section 9.4): a message still waiting at its
     * expiry is cancelled -- which purges its sealed content -- even while
     * its School is suspended (the sweeper calls this for every School).
     */
    public function expireIfDue(string $messageId): void
    {
        $finished = DB::transaction(function () use ($messageId): ?EmailMessage {
            $message = EmailMessage::query()->whereKey($messageId)->lockForUpdate()->first();

            if ($message === null || ! $message->status->awaitsSubmission() || $message->expires_at->isFuture()) {
                return null;
            }

            if ($message->status === EmailState::Submitting) {
                if ($message->processing_lease_expires_at !== null && $message->processing_lease_expires_at->isFuture()) {
                    return null; // in flight: its own outcome decides
                }
                $message->forceFill(['status' => EmailState::Pending, 'processing_lease_expires_at' => null])->save();
            }

            return $this->finish($message, EmailState::Cancelled, 'expired')['finished'];
        });

        if ($finished !== null) {
            $this->afterChange($finished);
        }
    }

    /** @return array{finished: EmailMessage} */
    private function finish(EmailMessage $message, EmailState $state, string $code, array $extra = []): array
    {
        $message->forceFill([...$extra,
            'status' => $state,
            'status_code' => mb_substr($code, 0, 48),
            'finished_at' => now(),
            'next_attempt_at' => null,
            'processing_lease_expires_at' => null,
        ])->save();

        return ['finished' => $message];
    }

    private function defer(EmailMessage $message, CarbonInterface $until, string $reason): null
    {
        $this->deferModel($message, $until, $reason);

        return null;
    }

    private function deferModel(EmailMessage $message, CarbonInterface $until, string $reason, array $extra = []): null
    {
        $message->forceFill([...$extra,
            'status' => EmailState::Pending,
            'status_code' => $reason,
            'next_attempt_at' => $until,
            'processing_lease_expires_at' => null,
        ])->save();

        return null;
    }

    /** After commit: the source's projection and the finished-message metric. */
    private function afterChange(EmailMessage $message): void
    {
        $message->refresh();

        $this->telemetry->message($message->purpose, match ($message->status) {
            EmailState::Submitted => 'submitted',
            EmailState::Failed => 'failed',
            EmailState::Suppressed => 'suppressed',
            EmailState::Cancelled => 'cancelled',
            default => 'none',
        });

        $this->sources->for($message->source_type)?->project($message);
    }

    private function outbound(EmailMessage $message): OutboundEmail
    {
        /** @var array{text?: string, html?: string|null, attachments?: list<array{disk: string, path: string, name: string, mime: string}>} $content */
        $content = $message->sealed_content ?? [];

        return new OutboundEmail(
            messageId: $message->id,
            rfcMessageId: (string) $message->rfc_message_id,
            idempotencyKey: EmailMessageIdentity::idempotencyKey($message->id),
            purpose: $message->purpose,
            fromAddress: $this->sender->fromAddress($message->from_mailbox),
            fromName: $message->from_display_name,
            to: $message->recipient(),
            subject: $message->subject,
            text: (string) ($content['text'] ?? ''),
            html: $content['html'] ?? null,
            attachments: $content['attachments'] ?? [],
        );
    }

    /** For operator tooling and tests: the adapter currently configured. */
    public function adapter(): ?EmailProviderAdapter
    {
        return $this->providers->adapter();
    }
}
