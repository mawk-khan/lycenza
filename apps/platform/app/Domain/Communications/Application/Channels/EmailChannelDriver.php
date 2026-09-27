<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Support\Email\EmailPurpose;
use App\Support\Email\OutboundEmailGateway;
use App\Support\Email\Providers\EmailProviderResolver;

/**
 * Phase 5A.3 §6: the EMAIL implementation of
 * App\Domain\Communications\Application\Channels\CommunicationChannelDriver,
 * registered into
 * App\Domain\Communications\Application\Channels\CommunicationChannelRegistry
 * exactly like InAppChannelDriver -- reached ONLY through
 * App\Jobs\ProcessCommunicationDeliveryJob, never called directly by a
 * controller/service.
 *
 * Destination resolution (brief §7/§8): this driver NEVER re-resolves
 * a live User.email at send time -- it reads
 * `$delivery->destination_snapshot['email']`, captured once at
 * delivery-creation time
 * (App\Domain\Communications\Application\AnnouncementService::publish()),
 * so a later change to the recipient's account email cannot alter a
 * historical delivery record's destination.
 *
 * Phase 0O.9A (ADR 0055 section 9.5): the driver no longer talks to a mail
 * transport. It HANDS the delivery to the platform email layer
 * (OutboundEmailGateway: one sealed `school_communication` message per
 * delivery, idempotent per delivery) and reports `accepted` -- handed over,
 * never "sent" or "delivered". From then on the email layer owns every
 * retry (rule 59: one retry owner) and its state is projected back onto
 * the delivery by CommunicationDeliveryEmailSource: submitted -> `sent`,
 * delivered -> `delivered`, bounced/complained -> `bounced`, suppressed or
 * provider-rejected -> `rejected`, expired -> `expired`, other failures ->
 * `failed`.
 */
final class EmailChannelDriver implements CommunicationChannelDriver
{
    public function __construct(
        private readonly OutboundEmailGateway $email,
        private readonly EmailProviderResolver $providers,
    ) {}

    public function channel(): CommunicationChannel
    {
        return CommunicationChannel::Email;
    }

    public function send(CommunicationDelivery $delivery): CommunicationDeliveryResult
    {
        // Re-checked at send time, not just at delivery-creation time
        // (brief §9's `email_channel_disabled` code exists for exactly
        // this race). `MAIL_PROVIDER=none` (email explicitly disabled on
        // this deployment) is the same honest refusal -- never a silent
        // "sent".
        if (! (bool) config('communications.channels.email.enabled') || ! $this->providers->enabled()) {
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
        // before anything is queued, with a stable machine-readable code
        // (brief §52). No email is ever sent with attachments silently
        // dropped. Attachments stay private-storage objects (never a
        // filesystem path), streamed by the provider adapter.
        $attachments = $message->attachments;
        $totalBytes = $attachments->sum('size_bytes');
        $maxBytes = (int) config('communications.attachments.email_max_total_size_mb') * 1024 * 1024;

        if ($totalBytes > $maxBytes) {
            return CommunicationDeliveryResult::failed(
                'attachment_email_size_exceeded',
                'This communication\'s attachments are too large to deliver by email.',
            );
        }

        $subject = $this->subjectFor($message);

        $queued = $this->email->queue(
            school: $school,
            purpose: EmailPurpose::SchoolCommunication,
            sourceId: $delivery->id,
            recipient: $email,
            subject: $subject,
            text: view('emails.communications.message', ['bodyText' => $message->body])->render(),
            html: view('emails.communications.message-html', ['bodyText' => $message->body, 'subject' => $subject])->render(),
            attachments: $attachments->map(fn ($a) => [
                'disk' => $a->storage_disk,
                'path' => $a->storage_path,
                'name' => $a->safe_display_name,
                'mime' => $a->mime_type,
            ])->values()->all(),
        );

        return CommunicationDeliveryResult::accepted($queued->id);
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
