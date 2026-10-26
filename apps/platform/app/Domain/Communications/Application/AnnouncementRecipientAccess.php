<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Infrastructure\CommunicationAnnouncement;
use App\Domain\Communications\Infrastructure\CommunicationAnnouncementRecipient;
use App\Domain\Communications\Infrastructure\CommunicationRecipient;
use App\Models\User;

/**
 * ORG.9D-1R1 (ADR 0076 §77): the ONE "did this User actually receive this
 * announcement" answer, shared by the announcement page and its attachment
 * download (the inbox lists use the same two facts as SQL in
 * CommunicationInboxReadModel::recipientOf()).
 *
 * A recipient is a User named by the immutable audience snapshot
 * (`communication_announcement_recipients.user_id`, written in the publish
 * transaction), or a linked Guardian/Student login holding a real in-app
 * delivery row (`communication_recipients.recipient_user_id`, Phase 5B.2).
 * Only a published announcement has recipients; a fee reminder is email-only
 * and never opens in the Hub (ORG.9D-2, §74). Capability never makes anyone
 * a recipient -- callers check their own capability first and this second.
 * Call it inside the announcement's School context (RLS).
 */
final class AnnouncementRecipientAccess
{
    public function isRecipient(CommunicationAnnouncement $announcement, User $user): bool
    {
        if (! $announcement->isPublished() || $announcement->isFeeReminder()) {
            return false;
        }

        return CommunicationAnnouncementRecipient::query()
            ->where('announcement_id', $announcement->id)
            ->where('user_id', $user->id)
            ->exists()
            || ($announcement->message_id !== null && CommunicationRecipient::query()
                ->where('message_id', $announcement->message_id)
                ->where('recipient_user_id', $user->id)
                ->exists());
    }
}
