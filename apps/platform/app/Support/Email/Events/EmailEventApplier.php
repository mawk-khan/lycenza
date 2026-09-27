<?php

namespace App\Support\Email\Events;

use App\Models\EmailEvent;
use App\Models\EmailMessage;
use App\Models\EmailProviderReference;
use App\Models\School;
use App\Support\Email\EmailKind;
use App\Support\Email\EmailSources;
use App\Support\Email\EmailState;
use App\Support\Email\EmailTelemetry;
use App\Support\Email\Suppression\EmailSuppressionService;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * ADR 0055 sections 11.3 and 12: applies one stored, normalized event.
 *
 * - The School is ALWAYS the stored message's, found through
 *   email_provider_references by (provider, provider message id). Nothing
 *   in the provider's payload names a School; an unknown id is recorded
 *   `unknown_message` and creates nothing.
 * - The state moves only along the explicit graph (EmailState). An event
 *   that would move a message backward (a late `deferred` after
 *   `delivered`, a second bounce) is recorded `stale` and changes nothing.
 * - Evidence still protects reputation: a permanent bounce suppresses the
 *   address for ALL mail; a complaint suppresses standard mail -- or all
 *   mail when the complaint was about critical mail; a provider-side
 *   suppression suppresses all mail. Suppression writes are idempotent.
 * - A soft bounce is informational (the provider owns its own retries); it
 *   never suppresses.
 * - A `delivered` event is transport evidence only: it never accepts an
 *   invitation or changes any authorization. Routine events are not
 *   audited (ADR 0055 section 21).
 */
final class EmailEventApplier
{
    public function __construct(
        private readonly TenantContext $context,
        private readonly EmailSuppressionService $suppressions,
        private readonly EmailSources $sources,
        private readonly EmailTelemetry $telemetry,
    ) {}

    public function apply(string $eventId): void
    {
        $event = EmailEvent::query()->find($eventId);

        if ($event === null || $event->result !== 'received') {
            return;
        }

        if ($event->type === EmailEventType::Ignored->value) {
            $this->close($event, 'ignored');

            return;
        }

        $reference = $event->provider_message_id === null ? null : EmailProviderReference::query()
            ->where('provider', $event->provider)
            ->where('provider_message_id', $event->provider_message_id)
            ->first();

        $school = $reference === null ? null : School::query()->find($reference->school_id);

        if ($reference === null || $school === null) {
            $this->close($event, 'unknown_message');

            return;
        }

        $changed = $this->context->withSchool($school, fn () => DB::transaction(function () use ($event, $reference): ?EmailMessage {
            $message = EmailMessage::query()->whereKey($reference->email_message_id)->lockForUpdate()->first();

            // Claim the event itself inside the same transaction.
            $claimed = EmailEvent::query()->whereKey($event->id)->where('result', 'received')
                ->update(['result' => 'applied', 'email_message_id' => $message?->id, 'processed_at' => now()]);

            if ($claimed === 0 || $message === null) {
                return null;
            }

            $type = EmailEventType::from($event->type);
            $this->suppress($message, $type, $event);

            [$target, $changes] = match ($type) {
                EmailEventType::Delivered => [EmailState::Delivered, ['delivered_at' => now()]],
                EmailEventType::Deferred, EmailEventType::BounceTransient => [EmailState::Deferred, []],
                EmailEventType::BouncePermanent => [EmailState::Bounced, ['bounced_at' => now(), 'status_code' => 'bounce_'.($event->bounce_class ?? 'other'), 'finished_at' => now()]],
                EmailEventType::Complaint => [EmailState::Complained, ['complained_at' => now(), 'status_code' => 'complaint', 'finished_at' => now()]],
                EmailEventType::Rejected => [EmailState::Failed, ['status_code' => 'provider_rejected', 'finished_at' => now()]],
                EmailEventType::Ignored => [null, []],
            };

            if ($target === null || ! $message->status->observesProviderEvents() || ! $message->status->canTransitionTo($target)) {
                EmailEvent::query()->whereKey($event->id)->update(['result' => 'stale']);

                return null;
            }

            $moved = $target !== $message->status;
            $message->forceFill([...$changes, 'status' => $target, 'last_event_at' => now()])->save();

            return $moved ? $message : null;
        }));

        if ($changed !== null) {
            $this->telemetry->message($changed->purpose, match ($changed->status) {
                EmailState::Delivered => 'delivered',
                EmailState::Deferred => 'deferred',
                EmailState::Bounced => 'bounced',
                EmailState::Complained => 'complained',
                EmailState::Failed => 'failed',
                default => 'none',
            });

            $this->context->withSchool($school, fn () => $this->sources->for($changed->source_type)?->project($changed));
        }
    }

    private function suppress(EmailMessage $message, EmailEventType $type, EmailEvent $event): void
    {
        [$scope, $reason] = match (true) {
            $type === EmailEventType::BouncePermanent => ['all', 'hard_bounce'],
            $type === EmailEventType::Complaint => [$message->kind === EmailKind::Critical ? 'all' : 'standard', 'complaint'],
            $type === EmailEventType::Rejected && $event->bounce_class === 'provider_suppressed' => ['all', 'provider_suppressed'],
            default => [null, null],
        };

        if ($scope !== null) {
            $this->suppressions->suppress($message->recipient(), $scope, $reason, $event->id, $message->id);
        }
    }

    private function close(EmailEvent $event, string $result): void
    {
        EmailEvent::query()->whereKey($event->id)->where('result', 'received')->update(['result' => $result, 'processed_at' => now()]);
    }
}
