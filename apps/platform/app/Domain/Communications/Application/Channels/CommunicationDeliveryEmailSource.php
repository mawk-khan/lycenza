<?php

namespace App\Domain\Communications\Application\Channels;

use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Models\EmailMessage;
use App\Support\Email\EmailSource;
use App\Support\Email\EmailState;
use App\Support\Observability\MetricsRecorder;

/**
 * Phase 0O.9A (ADR 0055 section 9.1): the Communications side of a
 * `school_communication` email. `communication_deliveries` stays the
 * per-recipient PRODUCT record; its email status is COPIED from the email
 * layer through this one mapping, using the existing status vocabulary:
 *
 *   email submitted / deferred  -> sent       (the provider accepted it;
 *                                              NOT delivered)
 *   email delivered             -> delivered
 *   email bounced / complained  -> bounced
 *   email suppressed            -> rejected   (failure_code email_suppressed)
 *   email failed provider_rejected -> rejected
 *   email failed (otherwise)    -> failed
 *   email cancelled, expired    -> expired
 *   email cancelled (otherwise) -> cancelled
 *
 * Each target only replaces the states that precede it (`sending`,
 * `accepted`, then `sent`, then `delivered` for a late bounce), so a
 * projection never moves a delivery backward and never overrides a
 * delivery the product itself finished. Failure reasons shown to School
 * users are fixed phrases -- never provider text.
 */
final class CommunicationDeliveryEmailSource implements EmailSource
{
    public const SOURCE_TYPE = 'communication_delivery';

    /** target delivery status => delivery statuses it may replace */
    private const REPLACES = [
        'sent' => ['sending', 'accepted'],
        'delivered' => ['sending', 'accepted', 'sent'],
        'bounced' => ['sending', 'accepted', 'sent', 'delivered'],
        'rejected' => ['sending', 'accepted', 'sent'],
        'failed' => ['sending', 'accepted', 'sent'],
        'expired' => ['sending', 'accepted'],
        'cancelled' => ['sending', 'accepted'],
    ];

    private const REASONS = [
        'email_bounced' => 'The recipient\'s mail server could not accept this email.',
        'email_complaint' => 'The recipient reported this email as unwanted.',
        'email_suppressed' => 'This address is blocked after an earlier bounce or complaint; the email was not sent.',
        'email_provider_rejected' => 'The email service refused to deliver this email.',
        'email_submission_failed' => 'The email could not be sent.',
        'email_expired' => 'The email could not be sent before it expired.',
    ];

    public function sourceType(): string
    {
        return self::SOURCE_TYPE;
    }

    public function isStillWanted(EmailMessage $message): bool
    {
        $status = CommunicationDelivery::query()->whereKey($message->source_id)->value('status');

        // Unfinished on the product side; a cancelled, expired or otherwise
        // finished delivery is never emailed.
        return in_array($status, ['pending', 'queued', 'sending', 'accepted'], true);
    }

    public function project(EmailMessage $message): void
    {
        [$target, $code, $timestamps] = match ($message->status) {
            EmailState::Submitted, EmailState::Deferred => ['sent', null, ['sent_at' => $message->submitted_at ?? now()]],
            EmailState::Delivered => ['delivered', null, ['sent_at' => $message->submitted_at ?? now(), 'delivered_at' => $message->delivered_at ?? now()]],
            EmailState::Bounced => ['bounced', 'email_bounced', ['failed_at' => now()]],
            EmailState::Complained => ['bounced', 'email_complaint', ['failed_at' => now()]],
            EmailState::Suppressed => ['rejected', 'email_suppressed', ['failed_at' => now()]],
            EmailState::Failed => $message->status_code === 'provider_rejected'
                ? ['rejected', 'email_provider_rejected', ['failed_at' => now()]]
                : ['failed', $message->status_code === 'expired' ? 'email_expired' : 'email_submission_failed', ['failed_at' => now()]],
            EmailState::Cancelled => $message->status_code === 'expired'
                ? ['expired', 'email_expired', ['failed_at' => now()]]
                : ['cancelled', null, []],
            default => [null, null, []],
        };

        if ($target === null) {
            return;
        }

        $changes = [...$timestamps, 'status' => $target, 'provider' => $message->provider, 'processing_lease_expires_at' => null];
        if ($code !== null) {
            $changes += ['failure_code' => $code, 'failure_reason' => self::REASONS[$code]];
        }

        $updated = CommunicationDelivery::query()
            ->whereKey($message->source_id)
            ->whereIn('status', self::REPLACES[$target])
            ->update($changes);

        // Counted once when it leaves our hands (`sent`) and again only for a
        // failure outcome; `delivered` refines a success already counted.
        if ($updated > 0 && $target !== 'delivered') {
            app(MetricsRecorder::class)->counter('lycenza_communication_deliveries_finished_total', 1, ['delivery_channel' => 'email', 'outcome' => $target]);
        }
    }
}
