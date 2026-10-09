<?php

namespace App\Domain\Communications\Application;

use App\Domain\Communications\Application\Exceptions\NotThreadParticipantException;
use App\Domain\Communications\Application\Exceptions\ThreadNotOpenException;
use App\Domain\Communications\Domain\CommunicationPriority;
use App\Domain\Communications\Events\CommunicationMessageCreated;
use App\Domain\Communications\Infrastructure\CommunicationAttachment;
use App\Domain\Communications\Infrastructure\CommunicationMessage;
use App\Domain\Communications\Infrastructure\CommunicationThread;
use App\Jobs\ProcessCommunicationDeliveryJob;
use App\Models\User;
use App\Support\Audit\AuditRecorder;
use App\Support\Observability\QueueName;
use App\Support\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Phase 5A.1 §2.9 -- the "smallest complete internal communication
 * path" from the brief §19: Message -> Recipient (one per other active
 * participant) -> in_app Delivery, all created in ONE transaction, with
 * delivery processing dispatched only after commit (root CLAUDE.md rule
 * 38/20). No external provider call happens anywhere in this class.
 * Recipient/delivery creation itself is extracted into
 * CommunicationDeliveryFactory (Phase 5A.2) so
 * AnnouncementService::publish() reuses it rather than duplicating it.
 */
class CommunicationMessageService
{
    public function __construct(
        private readonly AuditRecorder $audit,
        private readonly TenantContext $context,
        private readonly CommunicationDeliveryFactory $deliveryFactory,
    ) {}

    /**
     * @param  array<int, string>  $attachmentIds  Phase 5A.7 §15/§16 --
     *                                             ids of pending attachments the SENDER already uploaded against
     *                                             THIS thread (CommunicationAttachmentService::uploadForThread()),
     *                                             not yet linked to any message. Passed explicitly by the caller
     *                                             (never inferred as "every currently-unlinked attachment on the
     *                                             thread") so a concurrent, unrelated pending upload from another
     *                                             participant -- or a stale upload from an earlier abandoned
     *                                             compose -- can never be silently attached to a message it was
     *                                             never intended for.
     * @param  string|null  $idempotencyKey  POR.4 (ADR 0070 §27): a server-issued form
     *                                       key stored on the message; the unique index
     *                                       communication_messages_sender_idempotency_unique
     *                                       makes a duplicate a constraint violation. The
     *                                       caller owns replay/conflict (GuardianConversationService).
     *                                       The staff Hub passes none.
     */
    public function send(
        CommunicationThread $thread,
        User $sender,
        string $body,
        CommunicationPriority $priority = CommunicationPriority::Normal,
        array $attachmentIds = [],
        ?string $idempotencyKey = null,
    ): CommunicationMessage {
        return $this->context->withSchool($thread->school, function () use ($thread, $sender, $body, $priority, $attachmentIds, $idempotencyKey) {
            // The participant check must run INSIDE withSchool(): the
            // thread_participants row is RLS-protected, and this
            // service method may be called without an ambient
            // TenantContext already matching the thread's School (e.g.
            // a queued job or a direct service call from a test).
            $senderParticipant = $thread->participants()
                ->where('user_id', $sender->id)
                ->whereNull('left_at')
                ->first();

            if ($senderParticipant === null) {
                throw new NotThreadParticipantException;
            }

            // Phase 5A.7 §26/§28: a non-open Thread is not sendable --
            // enforced here (not just as a UI hint via `canReply`) so a
            // direct call/forged request cannot bypass it.
            if (! $thread->isOpen()) {
                throw new ThreadNotOpenException($thread->status);
            }

            $deliveryIds = [];

            $message = DB::transaction(function () use ($thread, $sender, $body, $priority, $attachmentIds, $idempotencyKey, &$deliveryIds) {
                $message = CommunicationMessage::query()->create([
                    'school_id' => $thread->school_id,
                    'thread_id' => $thread->id,
                    'sender_user_id' => $sender->id,
                    'message_type' => 'text',
                    'body' => $body,
                    'priority' => $priority->value,
                    'status' => 'sent',
                    'idempotency_key' => $idempotencyKey,
                ]);

                if ($attachmentIds !== []) {
                    // Scoped to THIS thread, unlinked, AND uploaded by
                    // THIS sender -- a forged/foreign attachment id
                    // (another participant's pending upload, another
                    // thread's, or already-linked) simply matches zero
                    // rows and is silently excluded rather than
                    // erroring the whole send (brief §17's orphan-
                    // safety spirit: a bad id here is a client bug, not
                    // grounds to fail an otherwise-valid message).
                    CommunicationAttachment::query()
                        ->where('communication_thread_id', $thread->id)
                        ->where('created_by_user_id', $sender->id)
                        ->whereNull('communication_message_id')
                        ->whereIn('id', $attachmentIds)
                        ->update(['communication_message_id' => $message->id]);
                }

                $recipientUserIds = $thread->participants()
                    ->where('user_id', '!=', $sender->id)
                    ->whereNull('left_at')
                    ->where('muted', false)
                    ->pluck('user_id');

                foreach ($recipientUserIds as $userId) {
                    $recipient = $this->deliveryFactory->createRecipient($thread->school_id, $message->id, $userId);

                    $deliveryIds[] = $this->deliveryFactory->createInAppDelivery($recipient)->id;
                }

                $thread->update(['last_activity_at' => now()]);

                $this->audit->school($thread->school, 'communication.message.created', actor: $sender, subject: $message, metadata: [
                    'threadId' => $thread->id,
                    'priority' => $message->priority,
                    'recipientCount' => count($deliveryIds),
                ]);

                event(new CommunicationMessageCreated(
                    $thread->school_id,
                    $thread->id,
                    $message->id,
                    $sender->id,
                    $message->priority,
                    count($deliveryIds),
                ));

                return $message;
            });

            foreach ($deliveryIds as $deliveryId) {
                ProcessCommunicationDeliveryJob::dispatch($thread->school_id, $deliveryId)
                    ->onQueue(QueueName::Notifications->value)
                    ->afterCommit();
            }

            return $message;
        });
    }
}
