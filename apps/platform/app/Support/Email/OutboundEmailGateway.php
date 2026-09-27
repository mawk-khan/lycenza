<?php

namespace App\Support\Email;

use App\Jobs\SubmitEmailMessageJob;
use App\Models\EmailMessage;
use App\Models\School;
use App\Support\Observability\QueueName;
use App\Support\Privacy\EmailNormalizer;
use Carbon\CarbonInterface;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Symfony\Component\Uid\UuidV7;

/**
 * ADR 0055 sections 6 and 9: the ONLY way application code sends email.
 * Business code never calls Laravel's Mail facade or a provider
 * (guard-tested): it asks this gateway to QUEUE a message, which
 *
 * 1. writes one durable, sealed `email_messages` row -- in the CALLER's
 *    transaction when there is one (the invitation outbox: the invitation,
 *    its audit record and its email commit or roll back together);
 * 2. dispatches the submission job only AFTER that transaction commits (a
 *    rolled-back business action leaves no email behind; a lost dispatch is
 *    recovered by `platform:email-messages-redispatch`).
 *
 * Nothing here contacts a provider, and "queued" never means "sent".
 * Content is rendered by the producer ONCE and sealed (encrypted); the
 * submission job sends exactly those bytes on every attempt.
 */
final class OutboundEmailGateway
{
    public function __construct(
        private readonly Repository $config,
        private readonly SenderIdentity $sender,
        private readonly EmailNormalizer $normalizer,
        private readonly EmailTelemetry $telemetry,
    ) {}

    /**
     * Idempotent per source: a second call for the same source returns the
     * existing message (a retried Communications job never creates a second
     * email).
     *
     * @param  list<array{disk: string, path: string, name: string, mime: string}>  $attachments
     */
    public function queue(
        School $school,
        EmailPurpose $purpose,
        string $sourceId,
        string $recipient,
        string $subject,
        string $text,
        ?string $html = null,
        array $attachments = [],
        ?CarbonInterface $expiresAt = null,
    ): EmailMessage {
        if (! $purpose->isImplemented()) {
            throw new InvalidArgumentException("Email purpose '{$purpose->value}' is reserved and cannot be sent.");
        }

        // Critical mail carries access links: its content lifetime is the
        // link's, which only the producer knows (ADR 0055 section 9.4).
        if ($purpose->kind() === EmailKind::Critical && $expiresAt === null) {
            throw new InvalidArgumentException('Critical email needs the lifetime of the link it carries.');
        }

        $sourceType = (string) $purpose->sourceType();
        $address = $this->normalizer->normalize($recipient);
        $id = (string) new UuidV7;
        $domain = $this->sender->sendingDomain();

        try {
            $message = DB::transaction(fn () => EmailMessage::query()->create([
                'id' => $id,
                'school_id' => $school->id,
                'purpose' => $purpose,
                'kind' => $purpose->kind(),
                'source_type' => $sourceType,
                'source_id' => $sourceId,
                'recipient_encrypted' => $address,
                'from_mailbox' => SenderIdentity::DEFAULT_MAILBOX,
                'from_display_name' => $this->sender->displayName($school->name),
                'subject' => HeaderValue::subject($subject, 'Message from '.$this->sender->platformName()),
                'sealed_content' => ['text' => $text, 'html' => $html, 'attachments' => $attachments],
                // Fixed now when the sending domain is known, else at the first
                // submission -- then never changed.
                'rfc_message_id' => $domain !== null ? EmailMessageIdentity::rfcMessageId($id, $domain) : null,
                'status' => EmailState::Pending,
                'next_attempt_at' => now(),
                'expires_at' => $expiresAt ?? now()->addHours((int) $this->config->get('email.submission.standard_ttl_hours')),
            ]));
        } catch (UniqueConstraintViolationException) {
            return EmailMessage::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->firstOrFail();
        }

        $this->telemetry->message($purpose, 'queued');

        SubmitEmailMessageJob::dispatch($school->id, $message->id)
            ->onQueue(QueueName::Notifications->value)
            ->afterCommit();

        return $message;
    }

    /**
     * Cancel a source's message that the provider has NOT accepted yet
     * (revoked or reissued invitation). Provider-accepted mail is never
     * "unsent": its evidence stays. Returns whether a message was cancelled.
     */
    public function cancelForSource(string $sourceType, string $sourceId, string $reason): bool
    {
        $message = EmailMessage::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->lockForUpdate()->first();

        if ($message === null || $message->status !== EmailState::Pending) {
            return false;
        }

        $message->forceFill([
            'status' => EmailState::Cancelled,
            'status_code' => $reason,
            'finished_at' => now(),
            'next_attempt_at' => null,
        ])->save();

        $this->telemetry->message($message->purpose, 'cancelled');

        return true;
    }

    public function forSource(string $sourceType, string $sourceId): ?EmailMessage
    {
        return EmailMessage::query()->where('source_type', $sourceType)->where('source_id', $sourceId)->first();
    }
}
