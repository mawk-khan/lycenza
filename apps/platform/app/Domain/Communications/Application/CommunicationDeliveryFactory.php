<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Domain\CommunicationChannel;
use App\Domain\Communications\Infrastructure\CommunicationDelivery;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use Illuminate\Database\UniqueConstraintViolationException;
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
    public function createRecipient(string $schoolId, string $messageId, string $recipientUserId): CommunicationRecipient
    {
        return CommunicationRecipient::query()->create([
            'school_id' => $schoolId,
            'message_id' => $messageId,
            'recipient_user_id' => $recipientUserId,
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
        try {
            // Wrapped in its own DB::transaction() so a constraint
            // violation only rolls back to a SAVEPOINT (Laravel opens
            // one automatically for a transaction nested inside an
            // already-open one) rather than aborting the entire
            // enclosing transaction -- without this, the catch below
            // would itself fail with "current transaction is aborted".
            return DB::transaction(fn () => CommunicationDelivery::query()->create([
                'school_id' => $recipient->school_id,
                'recipient_id' => $recipient->id,
                'channel' => CommunicationChannel::InApp->value,
                'status' => 'pending',
                'queued_at' => now(),
            ]));
        } catch (UniqueConstraintViolationException) {
            return CommunicationDelivery::query()
                ->where('recipient_id', $recipient->id)
                ->where('channel', CommunicationChannel::InApp->value)
                ->firstOrFail();
        }
    }
}
