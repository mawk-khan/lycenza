<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Support\Tenancy\SchoolOperationalGuard;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The one place a CommunicationRecipient + its `in_app`
 * CommunicationDelivery are created, extracted out of
 * CommunicationMessageService in Phase 5A.2 so
 * App\Domain\Communications\Application\AnnouncementService::publish()
 * reuses the exact same idempotent-creation logic (brief §16: "Do not
 * duplicate delivery creation logic") rather than a second copy.
 */
class CommunicationDeliveryFactory
{
    /**
     * Phase 5B.2 §40/§49: idempotent-by-construction (root CLAUDE.md
     * rule 30) -- the `(message_id, recipient_user_id)` unique
     * constraint is the authoritative guard. Never triggered by the
     * plain membership-audience loop (a userId only ever appears once
     * per chunk there), but genuinely reachable once a linked Guardian/
     * Student's IN_APP delivery
     * (App\Domain\Communications\Application\AnnouncementService::deliverInAppForLinkedDomainParty())
     * resolves to the SAME underlying User a School-wide/Individual
     * audience already reached in a hypothetical future combined
     * resolution -- defense-in-depth against exactly that, even though
     * today's audience types remain mutually exclusive per
     * Announcement.
     */
    public function createRecipient(string $schoolId, string $messageId, string $recipientUserId): CommunicationRecipient
    {
        try {
            return DB::transaction(fn () => CommunicationRecipient::query()->create([
                'school_id' => $schoolId,
                'message_id' => $messageId,
                'recipient_user_id' => $recipientUserId,
            ]));
        } catch (UniqueConstraintViolationException) {
            return CommunicationRecipient::query()
                ->where('message_id', $messageId)
                ->where('recipient_user_id', $recipientUserId)
                ->firstOrFail();
        }
    }

    /**
     * Phase 5B.1 §9: the Guardian-side counterpart to createRecipient()
     * -- reuses the exact same `communication_recipients` row shape
     * (widened by this checkpoint's migration to accept a nullable
     * `recipient_guardian_id` instead of `recipient_user_id`), so
     * everything downstream (CommunicationDelivery, ProcessCommunicationDeliveryJob,
     * the channel driver registry) needs zero changes to serve a
     * Guardian recipient.
     */
    public function createRecipientForGuardian(string $schoolId, string $messageId, string $recipientGuardianId): CommunicationRecipient
    {
        return CommunicationRecipient::query()->create([
            'school_id' => $schoolId,
            'message_id' => $messageId,
            'recipient_guardian_id' => $recipientGuardianId,
        ]);
    }

    /**
     * Idempotent-by-construction (root CLAUDE.md rule 30): the
     * unique(recipient_id, channel) constraint is the authoritative
     * guard, never a check-then-insert. A duplicate call for the same
     * recipient+channel returns the EXISTING row rather than raising.
     */
    public function createInAppDelivery(CommunicationRecipient $recipient): CommunicationDelivery
    {
        return $this->createDelivery($recipient, CommunicationChannel::InApp);
    }

    /**
     * Phase 5A.3 §8/§34: generalized over createInAppDelivery() so
     * App\Domain\Communications\Application\AnnouncementService::publish()
     * reuses the EXACT same idempotent-creation logic for `email`
     * deliveries too (brief §6: "Email must use the same pipeline as
     * IN_APP") -- no second delivery-creation code path.
     * `$destinationSnapshot` is captured HERE, at creation time, not
     * re-resolved later at send time (brief §8): whatever address a
     * channel driver ends up sending to is exactly what was true the
     * moment this row was written, independent of any later change to
     * the recipient's stored contact details.
     *
     * Phase 5A.9 -- `$availableAt`, when provided, means the delivery
     * plan already knows this recipient+channel must not transport
     * before that UTC instant (a quiet-hours deferral decided by
     * App\Domain\Communications\Application\Policy\CommunicationDeliveryTimingPolicyService,
     * never computed here). The row is created directly in the SAME
     * `queued` + `next_attempt_at` state
     * App\Jobs\ProcessCommunicationDeliveryJob::scheduleRetry() already
     * uses for a retry backoff -- no new status value, and
     * App\Console\Commands\RedispatchDueCommunicationDeliveries picks
     * it up once due without any changes of its own (brief §20/§23).
     * `null` (the default) preserves the exact pre-5A.9 immediate-send
     * behavior.
     *
     * @param  array<string, mixed>|null  $destinationSnapshot
     */
    public function createDelivery(CommunicationRecipient $recipient, CommunicationChannel $channel, ?array $destinationSnapshot = null, ?Carbon $availableAt = null): CommunicationDelivery
    {
        try {
            // Wrapped in its own DB::transaction() so a constraint
            // violation only rolls back to a SAVEPOINT (Laravel opens
            // one automatically for a transaction nested inside an
            // already-open one) rather than aborting the entire
            // enclosing transaction -- without this, the catch below
            // would itself fail with "current transaction is aborted".
            return DB::transaction(function () use ($recipient, $channel, $destinationSnapshot, $availableAt) {
                // Phase 0N.9 (ADR 0047 section 8): no new delivery for a
                // School that is not active. Read FOR SHARE, so it
                // serializes with a suspension; the caller's transaction
                // (a publish, an approval) rolls back whole.
                app(SchoolOperationalGuard::class)->requireOperational($recipient->school_id);

                return CommunicationDelivery::query()->create([
                    'school_id' => $recipient->school_id,
                    'recipient_id' => $recipient->id,
                    'channel' => $channel->value,
                    'status' => $availableAt !== null ? 'queued' : 'pending',
                    'destination_snapshot' => $destinationSnapshot,
                    'queued_at' => now(),
                    'next_attempt_at' => $availableAt,
                ]);
            });
        } catch (UniqueConstraintViolationException) {
            return CommunicationDelivery::query()
                ->where('recipient_id', $recipient->id)
                ->where('channel', $channel->value)
                ->firstOrFail();
        }
    }
}
