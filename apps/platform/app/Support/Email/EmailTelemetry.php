<?php

namespace App\Support\Email;

use App\Support\Observability\MetricsRecorder;

/**
 * ADR 0055 section 16: the six `lycenza_email_*` metrics and their CLOSED
 * label values. Never a School, recipient, domain, provider message id,
 * internal message id or template.
 */
final class EmailTelemetry
{
    public const MESSAGE_OUTCOMES = ['queued', 'submitted', 'delivered', 'deferred', 'bounced', 'complained', 'suppressed', 'failed', 'cancelled'];

    public const ATTEMPT_OUTCOMES = ['accepted', 'transient_failure', 'permanent_failure', 'auth_failure'];

    public const WEBHOOK_OUTCOMES = ['accepted', 'unauthenticated', 'too_large', 'malformed', 'duplicate', 'disabled'];

    /** @return list<string> */
    public static function messageClasses(): array
    {
        return EmailPurpose::values();
    }

    public function __construct(private readonly MetricsRecorder $metrics) {}

    public function message(EmailPurpose $purpose, string $outcome): void
    {
        if (in_array($outcome, self::MESSAGE_OUTCOMES, true)) {
            $this->metrics->counter('lycenza_email_messages_total', 1, ['message_class' => $purpose->value, 'outcome' => $outcome]);
        }
    }

    public function attempt(EmailPurpose $purpose, string $outcome): void
    {
        if (in_array($outcome, self::ATTEMPT_OUTCOMES, true)) {
            $this->metrics->counter('lycenza_email_submission_attempts_total', 1, ['message_class' => $purpose->value, 'outcome' => $outcome]);
        }
    }

    public function webhook(string $outcome): void
    {
        if (in_array($outcome, self::WEBHOOK_OUTCOMES, true)) {
            $this->metrics->counter('lycenza_email_webhook_requests_total', 1, ['outcome' => $outcome]);
        }
    }
}
