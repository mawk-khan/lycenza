<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Phase 5A.3 §6: the EMAIL implementation of
 * App\Domain\Communications\Application\Channels\CommunicationChannelDriver,
 * registered into
 * App\Domain\Communications\Application\Channels\CommunicationChannelRegistry
 * exactly like InAppChannelDriver -- reached ONLY through
 * App\Jobs\ProcessCommunicationDeliveryJob, never called directly by a
 * controller/service (brief §6/§23: no external mail I/O inside a
 * database transaction, always after-commit + queued).
 *
 * Destination resolution (brief §7/§8): this driver NEVER re-resolves
 * a live User.email at send time -- it reads
 * `$delivery->destination_snapshot['email']`, captured once at
 * delivery-creation time
 * (App\Domain\Communications\Application\AnnouncementService::publish()),
 * so a later change to the recipient's account email cannot alter a
 * historical delivery record's destination.
 */
final class EmailChannelDriver implements CommunicationChannelDriver
{
    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::Email;
    }

    public function send(CommunicationDelivery $delivery): CommunicationDeliveryResult
    {
        // Re-checked at send time, not just at delivery-creation time
        // (brief §9's `email_channel_disabled` code exists for exactly
        // this race: enabled when the Announcement was published,
        // disabled by an operator before the queued job actually runs).
        if (! (bool) config('communications.channels.email.enabled')) {
            return CommunicationDeliveryResult::failed(
                'email_channel_disabled',
                'Communication Hub email delivery is not currently enabled.',
            );
        }

        $email = $delivery->destination_snapshot['email'] ?? null;

        if ($email === null || $email === '') {
            return CommunicationDeliveryResult::failed(
                'recipient_email_missing',
                'The recipient has no usable email address on file.',
            );
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return CommunicationDeliveryResult::failed(
                'recipient_email_invalid',
                'The recipient email address on file is not a valid address.',
            );
        }

        $message = $delivery->recipient->message;
        $school = $delivery->school;

        // Phase 5A.6 §29: a SEPARATE, smaller threshold from the
        // canonical `attachments.max_total_size_mb` storage limit --
        // exceeding it fails ONLY this channel, deterministically and
        // before any transport call is attempted, with a stable
        // machine-readable code (brief §52). IN_APP remains unaffected
        // (it renders the same canonical attachments independently --
        // see InAppChannelDriver/the Announcement Show page) and no
        // email is ever sent with attachments silently dropped.
        $attachments = $message->attachments;
        $totalBytes = $attachments->sum('size_bytes');
        $maxBytes = (int) config('communications.attachments.email_max_total_size_mb') * 1024 * 1024;

        if ($totalBytes > $maxBytes) {
            return CommunicationDeliveryResult::failed(
                'attachment_email_size_exceeded',
                'This communication\'s attachments are too large to deliver by email.',
            );
        }

        $payload = new CommunicationEmailPayload(
            subject: $this->subjectFor($message),
            bodyText: $message->body,
            fromAddress: (string) config('mail.from.address'),
            // Brief §13: incorporates the School name into the DISPLAY
            // name only -- never forges a from address in the
            // School's own domain. Custom verified sender domains are
            // explicitly deferred (brief §13, phase doc §7).
            fromName: "{$school->name} via ".(string) config('mail.from.name'),
            attachments: $attachments->map(fn ($a) => [
                'disk' => $a->storage_disk,
                'path' => $a->storage_path,
                'displayName' => $a->safe_display_name,
                'mimeType' => $a->mime_type,
            ])->all(),
        );

        try {
            Mail::mailer(config('communications.channels.email.mailer'))
                ->to($email)
                ->send(new CommunicationMail($payload));
        } catch (Throwable $e) {
            // Never logs the exception message/trace -- it may embed
            // provider request/response detail (brief §9/§20/§27).
            Log::warning('communications.delivery.email.transport_failed', [
                'school_id' => $delivery->school_id,
                'delivery_id' => $delivery->id,
                'channel' => 'email',
            ]);

            // Broad and conservative: any transport exception is
            // treated as transient/retryable (bounded by
            // config('communications.delivery.max_attempts')) since a
            // generic Mailable/transport failure cannot be reliably
            // classified as permanent from here (brief §21). A future
            // provider-specific adapter can narrow this.
            return CommunicationDeliveryResult::failed(
                'email_transport_unavailable',
                'The configured mail transport rejected the send attempt.',
                retryable: true,
            );
        }

        // Brief §19/§22: SENT means "the configured application mail
        // transport accepted the send operation without throwing" --
        // it does NOT mean mailbox delivery, and no provider message
        // id is fabricated when the underlying transport (e.g. the
        // `log`/`array` transports used in dev/test) doesn't supply
        // one of its own.
        return CommunicationDeliveryResult::sent();
    }

    private function subjectFor(CommunicationMessage $message): string
    {
        if ($message->isAnnouncement()) {
            return $message->announcement->title;
        }

        // Brief §10: a deterministic, safe subject derived from
        // existing thread metadata -- never AI-generated.
        return $message->thread->subject ?? 'New message in Communication Hub';
    }
}
